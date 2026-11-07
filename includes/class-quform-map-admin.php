<?php
/**
 * Backend-Seite „Einstellungen → Quform Felder“: Gruppen und Felder pflegen, aus bestehenden
 * Plugin-Optionen importieren, Datei herunterladen.
 *
 * @package quform_map
 */
if (! defined ( 'ABSPATH' )) {
	exit ();
}

/**
 * Backend für die Feldzuordnung.
 */
class quform_map_admin {

	/**
	 * Slug der Seite.
	 *
	 * @var string
	 */
	const SLUG = 'quform_map';

	/**
	 * Nonce-Aktion für alle Formulare der Seite.
	 *
	 * @var string
	 */
	const NONCE = 'quform_map';

	/**
	 * Containertypen von Quform, deren Kinder unter 'elements' liegen.
	 *
	 * @var string[]
	 */
	const CONTAINER_TYPES = array (
			'page',
			'group',
			'row',
			'column'
	);

	/**
	 * Einzige Instanz.
	 *
	 * @var quform_map_admin|null
	 */
	private static $_instance = null;

	/**
	 * Hook-Suffix der Seite.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Formulare aus Quform: id => {name, active, elements: id => {label, type}}, null = nicht geladen.
	 *
	 * @var array|null
	 */
	private $forms = null;

	/**
	 * Fehler beim Speichern (Formular wird dann mit den eingegebenen Werten erneut angezeigt).
	 *
	 * @var string[]
	 */
	private $errors = array ();

	/**
	 * Eingegebene Gruppen nach fehlgeschlagenem Speichern, null = aus der Datei.
	 *
	 * @var array|null
	 */
	private $posted = null;

	/**
	 * Instanz holen.
	 *
	 * @return quform_map_admin
	 */
	public static function instance() {
		if (null === self::$_instance) {
			self::$_instance = new self ();
		}
		return self::$_instance;
	}

	/**
	 * Hooks registrieren.
	 */
	private function __construct() {
		add_action ( 'admin_menu', array ( $this, 'add_page' ) );
		add_action ( 'admin_enqueue_scripts', array ( $this, 'enqueue' ) );
		add_action ( 'admin_post_quform_map_download', array ( $this, 'download' ) );
		add_action ( 'admin_notices', array ( $this, 'notice_load_error' ) );
		add_filter ( 'plugin_action_links_' . plugin_basename ( QUFORM_MAP_PLUGIN_FILE ), array ( $this, 'action_links' ) );
	}

	/**
	 * URL der Seite.
	 *
	 * @param array $args zusätzliche Parameter
	 * @return string
	 */
	private function page_url(array $args = array ()) {
		return add_query_arg ( array_merge ( array ( 'page' => self::SLUG ), $args ), admin_url ( 'options-general.php' ) );
	}

	/**
	 * Link „Einstellungen“ in der Plugin-Liste.
	 *
	 * @param array $links Links
	 * @return array
	 */
	public function action_links($links) {
		array_unshift ( $links, '<a href="' . esc_url ( $this->page_url () ) . '">' . esc_html__ ( 'Einstellungen', 'quform_map' ) . '</a>' );
		return $links;
	}

	/**
	 * Menüeintrag unter Einstellungen.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook = ( string ) add_options_page ( __ ( 'Quform Felder', 'quform_map' ), __ ( 'Quform Felder', 'quform_map' ), 'manage_options', self::SLUG, array (
				$this,
				'render'
		) );
		if ('' !== $this->hook) {
			add_action ( 'load-' . $this->hook, array ( $this, 'handle_post' ) );
		}
	}

	/**
	 * Skript und Stil nur auf der eigenen Seite.
	 *
	 * @param string $hook_suffix aktuelle Seite
	 * @return void
	 */
	public function enqueue($hook_suffix) {
		if ($hook_suffix !== $this->hook) {
			return;
		}
		$base = plugin_dir_url ( QUFORM_MAP_PLUGIN_FILE );
		wp_enqueue_style ( 'quform-map-admin', $base . 'assets/css/admin.css', array (), QUFORM_MAP_VERSION );
		wp_enqueue_script ( 'quform-map-admin', $base . 'assets/js/admin.js', array ( 'jquery' ), QUFORM_MAP_VERSION, true );

		$forms = array ();
		foreach ( $this->get_forms () as $id => $form ) {
			$elements = array ();
			foreach ( $form ['elements'] as $element_id => $element ) {
				$elements [] = array (
						$element_id,
						$element ['label'],
						$element ['type']
				);
			}
			$forms [$id] = array (
					'name' => $form ['name'],
					'elements' => $elements
			);
		}
		wp_add_inline_script ( 'quform-map-admin', 'var quformMapAdmin = ' . wp_json_encode ( array (
				'forms' => ( object ) $forms,
				'missing' => __ ( 'nicht im Formular', 'quform_map' ),
				'choose' => __ ( '— Feld wählen —', 'quform_map' )
		) ) . ';', 'before' );
	}

	/**
	 * Hinweis für Administratoren, wenn die Datei fehlerhaft ist (andere Plugins bekommen dann keine IDs).
	 *
	 * @return void
	 */
	public function notice_load_error() {
		if (! current_user_can ( 'manage_options' )) {
			return;
		}
		$error = quform_map::last_error ();
		if ('' === $error) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Quform Felder:</strong> ' . esc_html ( $error ) . '</p></div>';
	}

	/*
	 * ------------------------------------------------------------------
	 * Quform auslesen
	 * ------------------------------------------------------------------
	 */

	/**
	 * Alle Formulare aus Quform (ohne Papierkorb).
	 *
	 * @return array id => {name, active, elements: id => {label, type}}
	 */
	private function get_forms() {
		if (null !== $this->forms) {
			return $this->forms;
		}
		$this->forms = array ();
		if (! function_exists ( 'quform' )) {
			return $this->forms;
		}
		try {
			$configs = quform ( 'repository' )->allForms ();
		} catch ( Throwable $e ) {
			error_log ( '[quform_map] Quform-Formulare nicht lesbar: ' . $e->getMessage () );
			return $this->forms;
		}
		foreach ( ( array ) $configs as $config ) {
			if (! is_array ( $config ) || ! empty ( $config ['trashed'] )) {
				continue;
			}
			$elements = array ();
			$this->collect_elements ( ( array ) ($config ['elements'] ?? array ()), $elements, '' );
			$this->forms [( int ) ($config ['id'] ?? 0)] = array (
					'name' => ( string ) ($config ['name'] ?? ''),
					'active' => ! empty ( $config ['active'] ),
					'elements' => $elements
			);
		}
		ksort ( $this->forms );
		return $this->forms;
	}

	/**
	 * Sammelt rekursiv alle Felder (keine Container) einer Formular-Konfiguration.
	 *
	 * @param array $elements Element-Konfigurationen
	 * @param array $out Ergebnis id => {label, type}
	 * @param string $context Bezeichnung der umgebenden Gruppe
	 * @return void
	 */
	private function collect_elements(array $elements, array &$out, $context) {
		foreach ( $elements as $element ) {
			if (! is_array ( $element ) || ! isset ( $element ['type'], $element ['id'] )) {
				continue;
			}
			$type = ( string ) $element ['type'];
			if (in_array ( $type, self::CONTAINER_TYPES, true )) {
				$inner = $context;
				if ('group' === $type) {
					$label = $this->element_label ( $element );
					if ('' !== $label) {
						$inner = ('' !== $context ? $context . ' › ' : '') . $label;
					}
				}
				$this->collect_elements ( ( array ) ($element ['elements'] ?? array ()), $out, $inner );
				continue;
			}
			if ('honeypot' === $type) {
				continue;
			}
			$label = $this->element_label ( $element );
			$out [( int ) $element ['id']] = array (
					'label' => ('' !== $context ? $context . ' › ' : '') . ('' !== $label ? $label : '(' . __ ( 'ohne Bezeichnung', 'quform_map' ) . ')'),
					'type' => $type
			);
		}
	}

	/**
	 * Bezeichnung eines Elements: Admin Label, sonst Label.
	 *
	 * @param array $element Element-Konfiguration
	 * @return string
	 */
	private function element_label(array $element) {
		foreach ( array ( 'adminLabel', 'label' ) as $key ) {
			$label = trim ( wp_strip_all_tags ( ( string ) ($element [$key] ?? '') ) );
			if ('' !== $label) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Element eines Formulars oder null.
	 *
	 * @param int $form_id Formular-ID
	 * @param int $element_id Element-ID
	 * @return array|null
	 */
	private function find_element($form_id, $element_id) {
		$forms = $this->get_forms ();
		return $forms [( int ) $form_id] ['elements'] [( int ) $element_id] ?? null;
	}

	/**
	 * Kommentare für die Datei: [gruppe][feld] => „Bezeichnung (Typ)“, [gruppe]['#form'] => Formularname.
	 *
	 * @param array $data Zuordnung
	 * @return array
	 */
	private function comments_for(array $data) {
		$comments = array ();
		if (! count ( $this->get_forms () )) {
			return $comments;
		}
		$forms = $this->get_forms ();
		foreach ( $data as $key => $group ) {
			$comments [$key] ['#form'] = isset ( $forms [( int ) $group ['form']] ) ? $forms [( int ) $group ['form']] ['name'] : 'Formular nicht gefunden';
			foreach ( ( array ) $group ['fields'] as $field => $id ) {
				$element = $this->find_element ( ( int ) $group ['form'], ( int ) $id );
				$comments [$key] [$field] = null === $element ? 'nicht im Formular gefunden' : $element ['label'] . ' (' . $element ['type'] . ')';
			}
		}
		return $comments;
	}

	/*
	 * ------------------------------------------------------------------
	 * Speichern, Import, Download
	 * ------------------------------------------------------------------
	 */

	/**
	 * Verarbeitet die Formulare der Seite (vor der Ausgabe, damit umgeleitet werden kann).
	 *
	 * @return void
	 */
	public function handle_post() {
		if ('POST' !== ($_SERVER ['REQUEST_METHOD'] ?? '') || ! isset ( $_POST ['qf_action'] )) {
			return;
		}
		if (! current_user_can ( 'manage_options' )) {
			wp_die ( esc_html__ ( 'Keine Berechtigung.', 'quform_map' ) );
		}
		check_admin_referer ( self::NONCE );

		$action = sanitize_key ( wp_unslash ( $_POST ['qf_action'] ) );
		if ('save' === $action) {
			$this->handle_save ();
		} elseif ('import' === $action) {
			$this->handle_import ();
		}
	}

	/**
	 * Speichern: Eingaben prüfen, bei Fehlern erneut anzeigen, sonst Datei schreiben.
	 *
	 * @return void
	 */
	private function handle_save() {
		$raw_groups = isset ( $_POST ['groups'] ) && is_array ( $_POST ['groups'] ) ? wp_unslash ( $_POST ['groups'] ) : array ();
		$new = isset ( $_POST ['new_group'] ) && is_array ( $_POST ['new_group'] ) ? wp_unslash ( $_POST ['new_group'] ) : array ();
		if ('' !== trim ( ( string ) ($new ['key'] ?? '') )) {
			$new ['fields'] = array ();
			$raw_groups ['new'] = $new;
		}

		$rows = array ();
		$data = array ();
		$errors = array ();
		foreach ( $raw_groups as $raw ) {
			if (! is_array ( $raw ) || ! empty ( $raw ['delete'] )) {
				continue;
			}
			$key = strtolower ( sanitize_text_field ( ( string ) ($raw ['key'] ?? '') ) );
			$row = array (
					'key' => $key,
					'form' => absint ( $raw ['form'] ?? 0 ),
					'fields' => array ()
			);
			/* translators: %s: Schlüssel der Gruppe */
			$name = '' !== $key ? $key : __ ( '(ohne Schlüssel)', 'quform_map' );
			if (! quform_map::is_valid_key ( $key )) {
				$errors [] = sprintf ( __ ( 'Gruppe „%s“: ungültiger Schlüssel (a–z, 0–9, _; beginnt mit Buchstabe).', 'quform_map' ), $name );
			} elseif (isset ( $data [$key] )) {
				$errors [] = sprintf ( __ ( 'Gruppe „%s“ ist doppelt.', 'quform_map' ), $name );
			}

			$fields = array ();
			foreach ( ( array ) ($raw ['fields'] ?? array ()) as $raw_field ) {
				if (! is_array ( $raw_field ) || ! empty ( $raw_field ['delete'] )) {
					continue;
				}
				$field = strtolower ( sanitize_text_field ( ( string ) ($raw_field ['key'] ?? '') ) );
				$id = absint ( $raw_field ['id'] ?? 0 );
				if ('' === $field && 0 === $id) {
					continue;
				}
				$row ['fields'] [] = array (
						'key' => $field,
						'id' => $id
				);
				if (! quform_map::is_valid_key ( $field )) {
					$errors [] = sprintf ( __ ( 'Gruppe „%1$s“: ungültiger Feldschlüssel „%2$s“.', 'quform_map' ), $name, $field );
				} elseif (isset ( $fields [$field] )) {
					$errors [] = sprintf ( __ ( 'Gruppe „%1$s“: Feld „%2$s“ ist doppelt.', 'quform_map' ), $name, $field );
				} elseif ($id <= 0) {
					$errors [] = sprintf ( __ ( 'Gruppe „%1$s“: Feld „%2$s“ hat keine Quform-ID.', 'quform_map' ), $name, $field );
				}
				$fields [$field] = $id;
			}
			$rows [] = $row;
			if (quform_map::is_valid_key ( $key ) && ! isset ( $data [$key] )) {
				$data [$key] = array (
						'form' => $row ['form'],
						'fields' => $fields
				);
			}
		}

		if (count ( $errors )) {
			$this->errors = $errors;
			$this->posted = $rows;
			return;
		}

		$result = quform_map::save ( $data, $this->comments_for ( $data ) );
		if (is_wp_error ( $result )) {
			$this->errors = array ( $result->get_error_message () );
			$this->posted = $rows;
			return;
		}
		$this->redirect_with_notice ( 'success', __ ( 'Gespeichert, die Datei wurde neu geschrieben.', 'quform_map' ) );
	}

	/**
	 * Import aus einer bestehenden Plugin-Option ({quform_id, id_<feld>…}) in eine Gruppe.
	 *
	 * @return void
	 */
	private function handle_import() {
		$option = sanitize_text_field ( wp_unslash ( ( string ) ($_POST ['import_option'] ?? '') ) );
		$key = strtolower ( sanitize_text_field ( wp_unslash ( ( string ) ($_POST ['import_group'] ?? '') ) ) );
		if ('' === $key) {
			$key = $this->group_key_from_option ( $option );
		}
		if (! in_array ( $option, $this->import_candidates (), true )) {
			$this->redirect_with_notice ( 'error', __ ( 'Diese Option kann nicht importiert werden.', 'quform_map' ) );
		}
		if (! quform_map::is_valid_key ( $key )) {
			$this->redirect_with_notice ( 'error', sprintf ( __ ( 'Ungültiger Gruppenschlüssel „%s“.', 'quform_map' ), $key ) );
		}

		$values = get_option ( $option );
		$fields = array ();
		$invalid = array ();
		$empty = 0;
		foreach ( ( array ) $values as $name => $value ) {
			$name = ( string ) $name;
			if (0 !== strncmp ( $name, 'id_', 3 )) {
				continue;
			}
			$field = strtolower ( substr ( $name, 3 ) );
			if (! quform_map::is_valid_key ( $field )) {
				$invalid [] = $name;
				continue;
			}
			if (! is_numeric ( $value ) || ( int ) $value <= 0) {
				++ $empty;
				continue;
			}
			$fields [$field] = ( int ) $value;
		}

		$form_id = absint ( $values ['quform_id'] ?? 0 );
		$data = quform_map::all ();
		$existing = $data [$key] ?? array (
				'form' => 0,
				'fields' => array ()
		);
		$data [$key] = array (
				'form' => $form_id > 0 ? $form_id : $existing ['form'],
				'fields' => array_merge ( $existing ['fields'], $fields )
		);

		$result = quform_map::save ( $data, $this->comments_for ( $data ) );
		if (is_wp_error ( $result )) {
			$this->redirect_with_notice ( 'error', $result->get_error_message () );
		}
		$message = sprintf ( __ ( '%1$d Felder aus „%2$s“ in Gruppe „%3$s“ übernommen, %4$d leere übersprungen.', 'quform_map' ), count ( $fields ), $option, $key, $empty );
		if (count ( $invalid )) {
			$message .= ' ' . sprintf ( __ ( 'Nicht übernommen (Schlüssel ungültig): %s', 'quform_map' ), implode ( ', ', $invalid ) );
		}
		$this->redirect_with_notice ( 'success', $message );
	}

	/**
	 * Optionen, die wie die bisherigen Plugin-Einstellungen aufgebaut sind (Array mit quform_id).
	 *
	 * @return string[]
	 */
	private function import_candidates() {
		global $wpdb;
		$names = $wpdb->get_col ( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%quform%' ORDER BY option_name" );
		$out = array ();
		foreach ( ( array ) $names as $name ) {
			$value = get_option ( $name );
			if (is_array ( $value ) && array_key_exists ( 'quform_id', $value )) {
				$out [] = ( string ) $name;
			}
		}
		return $out;
	}

	/**
	 * Vorschlag für den Gruppenschlüssel aus dem Optionsnamen (z. B. meinplugin_anmeldung_quform → meinplugin_anmeldung).
	 *
	 * @param string $option Optionsname
	 * @return string
	 */
	private function group_key_from_option($option) {
		$key = preg_replace ( '/_?quform_?/', '', strtolower ( ( string ) $option ) );
		$key = trim ( preg_replace ( '/[^a-z0-9_]+/', '_', ( string ) $key ), '_' );
		return '' !== $key ? $key : 'formular';
	}

	/**
	 * Hinweis merken und zur Seite zurück.
	 *
	 * @param string $type success|error
	 * @param string $message Text
	 * @return never
	 */
	private function redirect_with_notice($type, $message) {
		set_transient ( 'quform_map_notice_' . get_current_user_id (), array (
				'type' => $type,
				'message' => $message
		), 120 );
		wp_safe_redirect ( $this->page_url () );
		exit ();
	}

	/**
	 * Lädt die aktuelle Datei herunter.
	 *
	 * @return void
	 */
	public function download() {
		if (! current_user_can ( 'manage_options' )) {
			wp_die ( esc_html__ ( 'Keine Berechtigung.', 'quform_map' ) );
		}
		check_admin_referer ( 'quform_map_download' );
		$file = quform_map::file ();
		if (! is_readable ( $file )) {
			wp_die ( esc_html__ ( 'Die Datei existiert noch nicht.', 'quform_map' ) );
		}
		nocache_headers ();
		header ( 'Content-Type: text/plain; charset=utf-8' );
		header ( 'Content-Disposition: attachment; filename="' . basename ( $file ) . '"' );
		header ( 'Content-Length: ' . filesize ( $file ) );
		readfile ( $file );
		exit ();
	}

	/*
	 * ------------------------------------------------------------------
	 * Ausgabe
	 * ------------------------------------------------------------------
	 */

	/**
	 * Gruppen für die Anzeige: nach Fehler die Eingaben, sonst aus der Datei.
	 *
	 * @return array Liste von {key, form, fields: Liste von {key, id}}
	 */
	private function rows() {
		if (null !== $this->posted) {
			return $this->posted;
		}
		$rows = array ();
		foreach ( quform_map::all () as $key => $group ) {
			$fields = array ();
			foreach ( $group ['fields'] as $field => $id ) {
				$fields [] = array (
						'key' => $field,
						'id' => $id
				);
			}
			$rows [] = array (
					'key' => $key,
					'form' => $group ['form'],
					'fields' => $fields
			);
		}
		return $rows;
	}

	/**
	 * Seite ausgeben.
	 *
	 * @return void
	 */
	public function render() {
		if (! current_user_can ( 'manage_options' )) {
			return;
		}
		$forms = $this->get_forms ();
		$file = quform_map::file ();
		$rows = $this->rows ();

		echo '<div class="wrap qf">';
		echo '<h1>' . esc_html__ ( 'Quform Felder', 'quform_map' ) . '</h1>';

		$notice = get_transient ( 'quform_map_notice_' . get_current_user_id () );
		if (is_array ( $notice )) {
			delete_transient ( 'quform_map_notice_' . get_current_user_id () );
			echo '<div class="notice notice-' . ('error' === $notice ['type'] ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html ( ( string ) $notice ['message'] ) . '</p></div>';
		}
		if (count ( $this->errors )) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__ ( 'Nicht gespeichert:', 'quform_map' ) . '</strong></p><ul>';
			foreach ( $this->errors as $error ) {
				echo '<li>' . esc_html ( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		$this->render_status ( $file, $forms );

		echo '<form method="post" action="' . esc_url ( $this->page_url () ) . '" class="qf-main">';
		wp_nonce_field ( self::NONCE );
		echo '<input type="hidden" name="qf_action" value="save">';

		if (! count ( $rows )) {
			echo '<p>' . esc_html__ ( 'Noch keine Gruppen. Lege unten eine an oder importiere die bisherigen Einstellungen.', 'quform_map' ) . '</p>';
		}
		foreach ( $rows as $index => $row ) {
			$this->render_group ( 'g' . $index, $row, $forms );
		}

		echo '<div class="qf-group qf-new">';
		echo '<h2>' . esc_html__ ( 'Neue Gruppe', 'quform_map' ) . '</h2>';
		echo '<p class="qf-head">';
		echo '<label>' . esc_html__ ( 'Schlüssel', 'quform_map' ) . ' <input type="text" name="new_group[key]" pattern="[a-z][a-z0-9_]*" placeholder="z. B. anmeldung"></label> ';
		echo '<label>' . esc_html__ ( 'Formular', 'quform_map' ) . ' ';
		$this->render_form_select ( 'new_group[form]', 0, $forms );
		echo '</label></p>';
		echo '<p class="description">' . esc_html__ ( 'Felder kannst du nach dem Speichern zuordnen.', 'quform_map' ) . '</p>';
		echo '</div>';

		submit_button ( __ ( 'Speichern und Datei schreiben', 'quform_map' ) );
		echo '</form>';

		$this->render_import ();
		$this->render_usage ();
		echo '</div>';
	}

	/**
	 * Status der Datei und von Quform.
	 *
	 * @param string $file Pfad der Datei
	 * @param array $forms Formulare
	 * @return void
	 */
	private function render_status($file, array $forms) {
		echo '<table class="widefat striped qf-status"><tbody>';
		echo '<tr><th>' . esc_html__ ( 'Datei', 'quform_map' ) . '</th><td><code>' . esc_html ( $file ) . '</code>';
		if (! defined ( 'QUFORM_MAP_FILE' )) {
			echo '<br><span class="description">' . esc_html__ ( 'Anderer Ort: in der wp-config.php define( \'QUFORM_MAP_FILE\', \'/pfad/datei.php\' );', 'quform_map' ) . '</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__ ( 'Stand', 'quform_map' ) . '</th><td>';
		if (is_file ( $file )) {
			echo esc_html ( wp_date ( 'd.m.Y H:i', ( int ) filemtime ( $file ) ) );
			$url = wp_nonce_url ( admin_url ( 'admin-post.php?action=quform_map_download' ), 'quform_map_download' );
			echo ' · <a href="' . esc_url ( $url ) . '">' . esc_html__ ( 'Herunterladen', 'quform_map' ) . '</a>';
		} else {
			echo esc_html__ ( 'Noch nicht vorhanden, wird beim ersten Speichern angelegt.', 'quform_map' );
		}
		$dir = dirname ( $file );
		if (! wp_is_writable ( $dir ) || (is_file ( $file ) && ! wp_is_writable ( $file ))) {
			echo '<br><strong class="qf-bad">' . esc_html__ ( 'Nicht beschreibbar – Speichern ist nicht möglich.', 'quform_map' ) . '</strong>';
		}
		echo '</td></tr>';

		echo '<tr><th>Quform</th><td>';
		if (! function_exists ( 'quform' )) {
			echo '<strong class="qf-bad">' . esc_html__ ( 'Nicht aktiv – Feld-IDs können nur als Zahl eingegeben werden.', 'quform_map' ) . '</strong>';
		} else {
			/* translators: %d: Anzahl Formulare */
			echo esc_html ( sprintf ( _n ( '%d Formular gefunden', '%d Formulare gefunden', count ( $forms ), 'quform_map' ), count ( $forms ) ) );
		}
		echo '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Eine Gruppe mit ihren Feldern.
	 *
	 * @param string $index Index im Formular
	 * @param array $row Gruppe {key, form, fields}
	 * @param array $forms Formulare
	 * @return void
	 */
	private function render_group($index, array $row, array $forms) {
		$base = 'groups[' . $index . ']';
		$form_id = ( int ) $row ['form'];
		$missing = 0;
		foreach ( $row ['fields'] as $field ) {
			if (count ( $forms ) && null === $this->find_element ( $form_id, ( int ) $field ['id'] )) {
				++ $missing;
			}
		}

		echo '<div class="qf-group" data-group="' . esc_attr ( $index ) . '">';
		echo '<h2>' . esc_html ( $row ['key'] );
		if (isset ( $forms [$form_id] )) {
			echo ' <span class="qf-form-name">' . esc_html ( '#' . $form_id . ' ' . $forms [$form_id] ['name'] ) . '</span>';
		}
		if ($missing) {
			/* translators: %d: Anzahl */
			echo ' <span class="qf-badge">' . esc_html ( sprintf ( _n ( '%d Feld nicht im Formular', '%d Felder nicht im Formular', $missing, 'quform_map' ), $missing ) ) . '</span>';
		}
		echo '</h2>';

		echo '<p class="qf-head">';
		echo '<label>' . esc_html__ ( 'Schlüssel', 'quform_map' ) . ' <input type="text" name="' . esc_attr ( $base ) . '[key]" value="' . esc_attr ( $row ['key'] ) . '" pattern="[a-z][a-z0-9_]*" required></label> ';
		echo '<label>' . esc_html__ ( 'Formular', 'quform_map' ) . ' ';
		$this->render_form_select ( $base . '[form]', $form_id, $forms );
		echo '</label> ';
		echo '<label class="qf-delete"><input type="checkbox" name="' . esc_attr ( $base ) . '[delete]" value="1"> ' . esc_html__ ( 'Gruppe löschen', 'quform_map' ) . '</label>';
		echo '</p>';

		echo '<table class="widefat qf-fields"><thead><tr>';
		echo '<th>' . esc_html__ ( 'Schlüssel', 'quform_map' ) . '</th>';
		echo '<th>' . esc_html__ ( 'Quform-Feld', 'quform_map' ) . '</th>';
		echo '<th>' . esc_html__ ( 'Name', 'quform_map' ) . '</th>';
		echo '<th class="qf-col-del">' . esc_html__ ( 'Löschen', 'quform_map' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $row ['fields'] as $f => $field ) {
			$this->render_field_row ( $base . '[fields][' . $f . ']', $form_id, $field ['key'], ( int ) $field ['id'], $forms, false );
		}
		$this->render_field_row ( $base . '[fields][n0]', $form_id, '', 0, $forms, false );
		echo '</tbody></table>';

		echo '<template class="qf-template">';
		$this->render_field_row ( $base . '[fields][__F__]', $form_id, '', 0, $forms, true );
		echo '</template>';
		echo '<p><button type="button" class="button qf-add">' . esc_html__ ( '+ Feld hinzufügen', 'quform_map' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Eine Feldzeile.
	 *
	 * @param string $base Name-Präfix
	 * @param int $form_id Formular-ID der Gruppe
	 * @param string $key Feldschlüssel
	 * @param int $id Element-ID
	 * @param array $forms Formulare
	 * @param bool $template Zeile für die Vorlage
	 * @return void
	 */
	private function render_field_row($base, $form_id, $key, $id, array $forms, $template) {
		$missing = $id > 0 && count ( $forms ) && null === $this->find_element ( $form_id, $id );
		echo '<tr class="qf-row' . ($missing ? ' qf-missing' : '') . '">';
		echo '<td><input type="text" class="qf-key" name="' . esc_attr ( $base ) . '[key]" value="' . esc_attr ( $key ) . '" pattern="[a-z][a-z0-9_]*"></td>';
		echo '<td>';
		if (count ( $forms )) {
			echo '<select class="qf-element" name="' . esc_attr ( $base ) . '[id]">';
			echo '<option value="">' . esc_html__ ( '— Feld wählen —', 'quform_map' ) . '</option>';
			foreach ( ( array ) ($forms [$form_id] ['elements'] ?? array ()) as $element_id => $element ) {
				echo '<option value="' . esc_attr ( ( string ) $element_id ) . '"' . selected ( $id, $element_id, false ) . '>' . esc_html ( '#' . $element_id . ' ' . $element ['label'] . ' (' . $element ['type'] . ')' ) . '</option>';
			}
			if ($missing) {
				echo '<option value="' . esc_attr ( ( string ) $id ) . '" selected>' . esc_html ( '#' . $id . ' – ' . __ ( 'nicht im Formular', 'quform_map' ) ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="number" min="0" class="small-text qf-element" name="' . esc_attr ( $base ) . '[id]" value="' . esc_attr ( $id > 0 ? ( string ) $id : '' ) . '">';
		}
		echo '</td>';
		echo '<td><code class="qf-name">' . esc_html ( $form_id > 0 && $id > 0 ? 'quform_' . $form_id . '_' . $id : '' ) . '</code></td>';
		echo '<td class="qf-col-del">';
		if (! $template && '' !== $key) {
			echo '<input type="checkbox" name="' . esc_attr ( $base ) . '[delete]" value="1">';
		}
		echo '</td></tr>';
	}

	/**
	 * Auswahl des Formulars.
	 *
	 * @param string $name Name des Felds
	 * @param int $current aktuelle Formular-ID
	 * @param array $forms Formulare
	 * @return void
	 */
	private function render_form_select($name, $current, array $forms) {
		if (! count ( $forms )) {
			echo '<input type="number" min="0" class="small-text qf-form" name="' . esc_attr ( $name ) . '" value="' . esc_attr ( $current > 0 ? ( string ) $current : '' ) . '">';
			return;
		}
		echo '<select class="qf-form" name="' . esc_attr ( $name ) . '">';
		echo '<option value="0">' . esc_html__ ( '— Formular wählen —', 'quform_map' ) . '</option>';
		foreach ( $forms as $id => $form ) {
			$label = '#' . $id . ' ' . $form ['name'] . ($form ['active'] ? '' : ' (' . __ ( 'inaktiv', 'quform_map' ) . ')');
			echo '<option value="' . esc_attr ( ( string ) $id ) . '"' . selected ( $current, $id, false ) . '>' . esc_html ( $label ) . '</option>';
		}
		if ($current > 0 && ! isset ( $forms [$current] )) {
			echo '<option value="' . esc_attr ( ( string ) $current ) . '" selected>' . esc_html ( '#' . $current . ' – ' . __ ( 'existiert nicht', 'quform_map' ) ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Import aus bestehenden Plugin-Optionen.
	 *
	 * @return void
	 */
	private function render_import() {
		$candidates = $this->import_candidates ();
		echo '<h2>' . esc_html__ ( 'Aus bisherigen Einstellungen importieren', 'quform_map' ) . '</h2>';
		if (! count ( $candidates )) {
			echo '<p>' . esc_html__ ( 'Keine Option mit quform_id gefunden.', 'quform_map' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__ ( 'Übernimmt quform_id als Formular und jedes id_<feld> als Feld <feld>. Bestehende Felder der Gruppe bleiben, gleichnamige werden überschrieben. Die Datei wird sofort geschrieben.', 'quform_map' ) . '</p>';
		echo '<form method="post" action="' . esc_url ( $this->page_url () ) . '" class="qf-import">';
		wp_nonce_field ( self::NONCE );
		echo '<input type="hidden" name="qf_action" value="import">';
		echo '<label>' . esc_html__ ( 'Option', 'quform_map' ) . ' <select name="import_option" class="qf-import-option">';
		foreach ( $candidates as $option ) {
			echo '<option value="' . esc_attr ( $option ) . '" data-group="' . esc_attr ( $this->group_key_from_option ( $option ) ) . '">' . esc_html ( $option ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__ ( 'in Gruppe', 'quform_map' ) . ' <input type="text" name="import_group" class="qf-import-group" value="' . esc_attr ( $this->group_key_from_option ( $candidates [0] ) ) . '" pattern="[a-z][a-z0-9_]*"></label> ';
		submit_button ( __ ( 'Importieren', 'quform_map' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Kurze Anleitung für PHP und JavaScript.
	 *
	 * @return void
	 */
	private function render_usage() {
		$code = <<<'CODE'
// PHP
$name  = quform_map::name( 'anmeldung', 'vorname' );      // 'quform_5_12'
$wert  = quform_map::value( $form, 'anmeldung', 'vorname' );
$wert  = quform_map::entry_value( $entry, 'anmeldung', 'vorname' );
$form  = quform_map::form_id( 'anmeldung' );              // 5
add_filter( 'quform_element_valid_' . quform_map::hook_id( 'anmeldung', 'email' ), … );

// Skript registrieren und Daten anhängen
wp_enqueue_script( 'mein-frontend', … );
quform_map::localize( 'mein-frontend', 'quformFelder', array( 'anmeldung' ) );

// JavaScript
jQuery( quformFelder.anmeldung.selectors.vorname ).val();         // '.quform-field-5_12'
quformFelder.anmeldung.form;                                       // 5
CODE;
		echo '<h2>' . esc_html__ ( 'Verwendung', 'quform_map' ) . '</h2>';
		echo '<pre class="qf-usage">' . esc_html ( $code ) . '</pre>';
	}
}
