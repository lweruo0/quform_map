<?php
/**
 * Zugriff auf die zentrale Feldzuordnung (Gruppe → Quform-Formular-ID und Feld-IDs).
 *
 * Die Zuordnung steht in einer PHP-Datei, die ein Array zurückgibt (Standard:
 * wp-content/quform_map.php, änderbar über die Konstante QUFORM_MAP_FILE in der
 * wp-config.php). Die Datei wird einmal pro Aufruf per include geladen und vom Opcache gehalten.
 *
 * Aufbau der Datei:
 * <code>
 * return array (
 * 	'anmeldung' => array (
 * 		'form' => 5, // Anmeldung
 * 		'fields' => array ( 'vorname' => 12, 'name' => 13 ),
 * 	),
 * );
 * </code>
 *
 * Die Klasse hat keine Abhängigkeiten außer WordPress und darf auch direkt eingebunden werden.
 *
 * @package quform_map
 */
if (! defined ( 'ABSPATH' )) {
	exit ();
}

if (class_exists ( 'quform_map' )) {
	return;
}

/**
 * Zentrale Feldzuordnung für Quform.
 */
class quform_map {

	/**
	 * Handle des Skripts, an dem die JS-Daten hängen (ohne eigene Datei).
	 */
	const JS_HANDLE = 'quform-map';

	/**
	 * Name der globalen JS-Variable mit allen Gruppen.
	 */
	const JS_OBJECT = 'quformfields';

	/**
	 * Geladene und bereinigte Zuordnung, null = noch nicht geladen.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * Fehler beim letzten Laden der Datei ('' = kein Fehler).
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Pfad der Zuordnungsdatei.
	 *
	 * @return string
	 */
	public static function file() {
		if (defined ( 'QUFORM_MAP_FILE' ) && '' !== ( string ) QUFORM_MAP_FILE) {
			return ( string ) QUFORM_MAP_FILE;
		}
		return WP_CONTENT_DIR . '/quform_map.php';
	}

	/**
	 * Komplette Zuordnung.
	 *
	 * @return array<string, array{form: int, fields: array<string, int>}>
	 */
	public static function all() {
		if (null === self::$data) {
			self::$data = self::load ();
		}
		return self::$data;
	}

	/**
	 * Verwirft die geladene Zuordnung, z. B. nach dem Speichern.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$data = null;
		self::$error = '';
	}

	/**
	 * Fehler beim Laden der Datei ('' = kein Fehler).
	 *
	 * @return string
	 */
	public static function last_error() {
		self::all ();
		return self::$error;
	}

	/**
	 * Eine Gruppe oder null, wenn es sie nicht gibt.
	 *
	 * @param string $group Schlüssel der Gruppe, z. B. 'anmeldung'
	 * @return array{form: int, fields: array<string, int>}|null
	 */
	public static function group($group) {
		$all = self::all ();
		return $all [( string ) $group] ?? null;
	}

	/**
	 * Quform-ID des Formulars einer Gruppe (0 = nicht gesetzt).
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @return int
	 */
	public static function form_id($group) {
		$g = self::group ( $group );
		return null === $g ? 0 : $g ['form'];
	}

	/**
	 * Quform-Element-ID eines Felds (0 = nicht gesetzt).
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds, z. B. 'vorname'
	 * @return int
	 */
	public static function id($group, $field) {
		$g = self::group ( $group );
		return null === $g ? 0 : ( int ) ($g ['fields'] [( string ) $field] ?? 0);
	}

	/**
	 * Prüft, ob Formular und Feld gesetzt sind.
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return bool
	 */
	public static function has($group, $field) {
		return self::form_id ( $group ) > 0 && self::id ( $group, $field ) > 0;
	}

	/**
	 * Quform-Name des Felds ('quform_5_12'), wie ihn getValue(), getElement() usw. erwarten.
	 * Leer, wenn Formular oder Feld nicht gesetzt sind.
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return string
	 */
	public static function name($group, $field) {
		if (! self::has ( $group, $field )) {
			return '';
		}
		return 'quform_' . self::form_id ( $group ) . '_' . self::id ( $group, $field );
	}

	/**
	 * Formular- und Element-ID im Format von Hook-Namen ('5_12'), z. B. für
	 * 'quform_element_valid_' . quform_map::hook_id( 'anmeldung', 'email' ).
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return string
	 */
	public static function hook_id($group, $field) {
		if (! self::has ( $group, $field )) {
			return '';
		}
		return self::form_id ( $group ) . '_' . self::id ( $group, $field );
	}

	/**
	 * CSS-Selektor des Eingabefelds im Frontend ('.quform-field-5_12').
	 *
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return string
	 */
	public static function selector($group, $field) {
		$hook_id = self::hook_id ( $group, $field );
		return '' === $hook_id ? '' : '.quform-field-' . $hook_id;
	}

	/**
	 * Wert eines Felds aus dem Formular (Quform_Form::getValue()).
	 *
	 * @param object $form Quform_Form
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return mixed '' wenn das Feld nicht gesetzt ist oder keinen Wert hat
	 */
	public static function value($form, $group, $field) {
		$name = self::name ( $group, $field );
		if ('' === $name || ! is_object ( $form ) || ! method_exists ( $form, 'getValue' )) {
			return '';
		}
		return $form->getValue ( $name ) ?? '';
	}

	/**
	 * Wert eines Felds aus einem gespeicherten Entry (Spalte element_<id>).
	 *
	 * @param array $entry Entry aus Quform
	 * @param string $group Schlüssel der Gruppe
	 * @param string $field Schlüssel des Felds
	 * @return string
	 */
	public static function entry_value(array $entry, $group, $field) {
		$id = self::id ( $group, $field );
		if ($id <= 0) {
			return '';
		}
		$value = $entry ['element_' . $id] ?? '';
		return is_scalar ( $value ) ? ( string ) $value : '';
	}

	/**
	 * Daten für JavaScript: je Gruppe Formular-ID, Feld-IDs, Quform-Namen und CSS-Selektoren.
	 *
	 * @param string[]|null $groups nur diese Gruppen, null = alle
	 * @return array
	 */
	public static function js_data($groups = null) {
		$out = array ();
		foreach ( self::all () as $key => $g ) {
			if (is_array ( $groups ) && ! in_array ( $key, $groups, true )) {
				continue;
			}
			$entry = array (
					'form' => $g ['form'],
					'fields' => $g ['fields'],
					'names' => array (),
					'selectors' => array ()
			);
			foreach ( array_keys ( $g ['fields'] ) as $field ) {
				$entry ['names'] [$field] = self::name ( $key, $field );
				$entry ['selectors'] [$field] = self::selector ( $key, $field );
			}
			$out [$key] = $entry;
		}
		return $out;
	}

	/**
	 * Registriert das Skript-Handle quform_map::JS_HANDLE ohne Datei. Daran hängt einmalig die
	 * globale JS-Variable quform_map::JS_OBJECT mit allen Gruppen.
	 *
	 * Nutzende Skripte geben das Handle als Abhängigkeit an:
	 * <code>
	 * wp_register_script( 'mein-frontend', $url, array( 'jquery', quform_map::JS_HANDLE ), $ver, true );
	 * </code>
	 * danach in JS: jQuery( quformfields.anmeldung.selectors.vorname ).val()
	 *
	 * Zusätzlich wird die globale Variable ajaxurl (URL von admin-ajax.php) gesetzt, falls sie fehlt –
	 * WordPress definiert sie nur im Backend.
	 *
	 * Wird über wp_enqueue_scripts/admin_enqueue_scripts (Priorität 1) aufgerufen; mehrfacher Aufruf
	 * ist unschädlich.
	 *
	 * @return bool true, wenn das Handle registriert ist
	 */
	public static function register_script() {
		if (wp_script_is ( self::JS_HANDLE, 'registered' )) {
			return true;
		}
		if (! wp_register_script ( self::JS_HANDLE, false, array (), defined ( 'QUFORM_MAP_VERSION' ) ? QUFORM_MAP_VERSION : false, true )) {
			return false;
		}
		$json = wp_json_encode ( ( object ) self::js_data () );
		// ajaxurl gibt es von WordPress nur im Backend; im Frontend hier einmalig setzen.
		$ajaxurl = 'var ajaxurl = window.ajaxurl || ' . wp_json_encode ( admin_url ( 'admin-ajax.php' ) ) . ';';
		return wp_add_inline_script ( self::JS_HANDLE, $ajaxurl . "\n" . 'var ' . self::JS_OBJECT . ' = ' . $json . ';' );
	}

	/**
	 * Kompatibilität für Plugins, die noch localize() aufrufen: macht quform_map::JS_HANDLE zur
	 * Abhängigkeit von $handle, statt eine eigene Variable anzulegen. Damit gibt es die Daten nur
	 * einmal und kein Plugin überschreibt die Variable eines anderen.
	 *
	 * Weicht $object_name von quform_map::JS_OBJECT ab, wird zusätzlich ein Alias angelegt
	 * (var $object_name = quformfields;). $groups wird ignoriert, es sind immer alle Gruppen enthalten.
	 *
	 * @deprecated 2026.11.13 quform_map::JS_HANDLE als Abhängigkeit angeben.
	 * @param string $handle Handle des Skripts (wp_register_script/wp_enqueue_script)
	 * @param string $object_name Name der JS-Variable
	 * @param string[]|null $groups wird ignoriert
	 * @return bool
	 */
	public static function localize($handle, $object_name = self::JS_OBJECT, $groups = null) {
		if (! preg_match ( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', ( string ) $object_name )) {
			return false;
		}
		if (! self::register_script ()) {
			return false;
		}
		$script = wp_scripts ()->query ( $handle, 'registered' );
		if (! $script) {
			return false;
		}
		if (! in_array ( self::JS_HANDLE, $script->deps, true )) {
			$script->deps [] = self::JS_HANDLE;
		}
		if (self::JS_OBJECT !== $object_name) {
			return wp_add_inline_script ( $handle, 'var ' . $object_name . ' = ' . self::JS_OBJECT . ';', 'before' );
		}
		return true;
	}

	/**
	 * Gültiger Schlüssel für Gruppen und Felder: Kleinbuchstaben, Ziffern, Unterstrich,
	 * beginnt mit einem Buchstaben.
	 *
	 * @param string $key Schlüssel
	 * @return bool
	 */
	public static function is_valid_key($key) {
		return is_string ( $key ) && 1 === preg_match ( '/^[a-z][a-z0-9_]{0,63}$/', $key );
	}

	/**
	 * Bereinigt eine Zuordnung: ungültige Schlüssel und IDs fallen weg, Typen werden vereinheitlicht.
	 *
	 * @param array $data Zuordnung
	 * @return array<string, array{form: int, fields: array<string, int>}>
	 */
	public static function normalize(array $data) {
		$out = array ();
		foreach ( $data as $key => $g ) {
			if (! self::is_valid_key ( $key ) || ! is_array ( $g )) {
				continue;
			}
			$fields = array ();
			foreach ( ( array ) ($g ['fields'] ?? array ()) as $field => $id ) {
				if (self::is_valid_key ( $field ) && is_numeric ( $id ) && ( int ) $id > 0) {
					$fields [$field] = ( int ) $id;
				}
			}
			$out [$key] = array (
					'form' => is_numeric ( $g ['form'] ?? 0 ) ? max ( 0, ( int ) $g ['form'] ) : 0,
					'fields' => $fields
			);
		}
		return $out;
	}

	/**
	 * Lädt die Datei.
	 *
	 * @return array
	 */
	private static function load() {
		self::$error = '';
		$file = self::file ();
		if (! is_file ( $file )) {
			return array ();
		}
		if (! is_readable ( $file )) {
			self::$error = 'Die Datei ' . $file . ' ist nicht lesbar.';
			return array ();
		}
		try {
			$data = include $file;
		} catch ( Throwable $e ) {
			self::$error = 'Fehler in ' . $file . ': ' . $e->getMessage () . ' (Zeile ' . $e->getLine () . ')';
			error_log ( '[quform_map] ' . self::$error );
			return array ();
		}
		if (! is_array ( $data )) {
			self::$error = 'Die Datei ' . $file . ' gibt kein Array zurück.';
			return array ();
		}
		return self::normalize ( $data );
	}

	/**
	 * Erzeugt den PHP-Code der Datei.
	 *
	 * @param array $data Zuordnung (wird bereinigt)
	 * @param array $comments optionale Kommentare: [gruppe][feld] => Text (z. B. Quform-Bezeichnung),
	 *        [gruppe]['#form'] => Text hinter der Formular-ID (z. B. Formularname)
	 * @return string
	 */
	public static function export(array $data, array $comments = array ()) {
		$data = self::normalize ( $data );
		$code = "<?php\n";
		$code .= "/**\n";
		$code .= " * Quform-Feldzuordnung, erzeugt von „Quform Felder“ am " . gmdate ( 'Y-m-d H:i' ) . " UTC.\n";
		$code .= " *\n";
		$code .= " * Darf von Hand bearbeitet werden. Beim Speichern im Backend (Einstellungen → Quform Felder)\n";
		$code .= " * wird die Datei neu geschrieben; eigene Kommentare gehen dabei verloren.\n";
		$code .= " * Zugriff: quform_map::name( 'gruppe', 'feld' ) usw.\n";
		$code .= " */\n";
		$code .= "if (! defined ( 'ABSPATH' )) {\n\texit ();\n}\n\n";
		$code .= "return array (\n";
		foreach ( $data as $key => $g ) {
			$code .= "\t" . var_export ( $key, true ) . " => array (\n";
			$code .= "\t\t'form' => " . $g ['form'] . ',';
			$comment = self::comment_text ( $comments [$key] ['#form'] ?? '' );
			if ('' !== $comment) {
				$code .= ' // ' . $comment;
			}
			$code .= "\n";
			$code .= "\t\t'fields' => array (\n";
			foreach ( $g ['fields'] as $field => $id ) {
				$code .= "\t\t\t" . var_export ( $field, true ) . ' => ' . $id . ',';
				$comment = self::comment_text ( $comments [$key] [$field] ?? '' );
				if ('' !== $comment) {
					$code .= ' // ' . $comment;
				}
				$code .= "\n";
			}
			$code .= "\t\t),\n";
			$code .= "\t),\n";
		}
		$code .= ");\n";
		return $code;
	}

	/**
	 * Macht einen Text als einzeiligen PHP-Kommentar sicher (kein Zeilenumbruch, kein ?>).
	 *
	 * @param mixed $text Text
	 * @return string
	 */
	private static function comment_text($text) {
		if (! is_scalar ( $text )) {
			return '';
		}
		$text = preg_replace ( '/\s+/u', ' ', wp_strip_all_tags ( ( string ) $text ) );
		$text = str_replace ( '?>', '? >', ( string ) $text );
		if (function_exists ( 'mb_substr' )) {
			$text = mb_substr ( $text, 0, 80 );
		}
		return trim ( $text );
	}

	/**
	 * Schreibt die Zuordnung in die Datei. Die bisherige Datei wird als *.bak.php gesichert.
	 *
	 * @param array $data Zuordnung
	 * @param array $comments optionale Kommentare je Feld, siehe export()
	 * @return true|WP_Error
	 */
	public static function save(array $data, array $comments = array ()) {
		$file = self::file ();
		$dir = dirname ( $file );
		if (! is_dir ( $dir ) || ! wp_is_writable ( $dir )) {
			return new WP_Error ( 'quform_map_dir', 'Das Verzeichnis ' . $dir . ' ist nicht beschreibbar.' );
		}
		$code = self::export ( $data, $comments );

		$tmp = $dir . '/.' . basename ( $file ) . '.' . wp_generate_password ( 8, false ) . '.tmp';
		if (false === file_put_contents ( $tmp, $code, LOCK_EX )) {
			return new WP_Error ( 'quform_map_write', 'Die Datei ' . $tmp . ' konnte nicht geschrieben werden.' );
		}
		if (is_file ( $file )) {
			@copy ( $file, preg_replace ( '/\.php$/', '', $file ) . '.bak.php' );
		}
		if (! @rename ( $tmp, $file )) {
			@unlink ( $tmp );
			return new WP_Error ( 'quform_map_rename', 'Die Datei ' . $file . ' konnte nicht ersetzt werden.' );
		}
		if (function_exists ( 'opcache_invalidate' )) {
			@opcache_invalidate ( $file, true );
		}
		self::reset ();
		return true;
	}
}
