# Quform Felder

WordPress-Plugin, das eine zentrale PHP-Datei mit sprechenden Namen für die Formular- und Feld-IDs von
[Quform 2](https://www.quform.com/) pflegt. Eigene Plugins und Themes lesen die IDs daraus, statt sie hart
zu codieren oder für jedes Feld eine eigene Einstellung anzulegen – in PHP und in JavaScript.

## Die Datei

Standardort: `wp-content/quform_map.php` (außerhalb des Plugin-Ordners, damit ein Update des Plugins
sie nicht überschreibt). Anderer Ort über die `wp-config.php`:

```php
define( 'QUFORM_MAP_FILE', '/pfad/zur/datei.php' );
```

Inhalt (wird vom Backend erzeugt, darf aber auch von Hand bearbeitet werden):

```php
return array (
	'anmeldung' => array (
		'form' => 5, // Anmeldung
		'fields' => array (
			'vorname' => 12, // Vorname (text)
			'email' => 14, // E-Mail (email)
		),
	),
);
```

Die Datei wird pro Aufruf einmal per `include` geladen und vom Opcache gehalten – genauso schnell wie
Konstanten. Beim Speichern wird die vorherige Fassung als `quform_map.bak.php` gesichert.

## Backend: Einstellungen → Quform Felder

- **Gruppen** (eine je Formular): Schlüssel und Quform-Formular. Der Schlüssel ist der Name, über den
  der Code die Gruppe anspricht.
- **Felder**: Schlüssel und das Quform-Element aus einer Auswahl (Bezeichnung, Typ, ID). Beim Wählen
  eines Elements wird ein Schlüssel vorgeschlagen. Felder, deren ID es im Formular nicht (mehr) gibt,
  werden rot markiert.
- **Import** aus bestehenden Optionen, die als Array mit `quform_id` und `id_<feld>` aufgebaut sind (alle
  Optionen mit „quform“ im Namen werden angeboten): `quform_id` wird zum Formular, jedes `id_<feld>` zum
  Feld `<feld>`.
- **Herunterladen** der aktuellen Datei (z. B. zum Versionieren).

Schlüssel: Kleinbuchstaben, Ziffern und `_`, beginnend mit einem Buchstaben.

## Verwendung in PHP

```php
quform_map::form_id( 'anmeldung' );                        // 5
quform_map::id( 'anmeldung', 'vorname' );                  // 12
quform_map::name( 'anmeldung', 'vorname' );                // 'quform_5_12'
quform_map::hook_id( 'anmeldung', 'email' );               // '5_14' für Hook-Namen
quform_map::selector( 'anmeldung', 'vorname' );            // '.quform-field-5_12'
quform_map::has( 'anmeldung', 'vorname' );                 // Formular und Feld gesetzt?
quform_map::value( $form, 'anmeldung', 'vorname' );        // $form->getValue( 'quform_5_12' )
quform_map::entry_value( $entry, 'anmeldung', 'vorname' ); // $entry['element_12']

add_filter( 'quform_element_valid_' . quform_map::hook_id( 'anmeldung', 'email' ), 'meine_pruefung', 10, 3 );
```

Nicht gesetzte Felder liefern `0` bzw. `''` – nie einen Fehler.

## Verwendung in JavaScript

Das Plugin registriert das Skript-Handle `quform-map` (`quform_map::JS_HANDLE`) ohne eigene Datei.
Daran hängt einmalig die globale Variable `quformfields` (`quform_map::JS_OBJECT`) mit **allen**
Gruppen. Nutzende Skripte geben das Handle nur als Abhängigkeit an:

```php
wp_enqueue_script( 'mein-frontend', $url, array( 'jquery', quform_map::JS_HANDLE ), $ver, true );
```

```js
quformfields.anmeldung.form;                 // 5
quformfields.anmeldung.fields.vorname;       // 12
quformfields.anmeldung.names.vorname;        // 'quform_5_12'
jQuery( quformfields.anmeldung.selectors.vorname ).val();  // '.quform-field-5_12'
```

WordPress gibt `quformfields` so genau einmal und immer vor dem nutzenden Skript aus. Ist Quform MAP
nicht aktiv, fehlt die Abhängigkeit und WordPress lädt das nutzende Skript gar nicht.

`quform_map::localize()` ist veraltet, funktioniert aber weiter: Es legt keine eigene Variable mehr an,
sondern macht `quform-map` zur Abhängigkeit des Skripts (bei abweichendem Namen zusätzlich ein Alias
`var meinName = quformfields;`). Der Parameter `$groups` wird ignoriert.

## Ladereihenfolge

WordPress lädt Plugins in der Reihenfolge der Option `active_plugins` (alphabetisch). Dieses Plugin
setzt sich beim Aktivieren an den Anfang, damit `quform_map` schon verfügbar ist, wenn andere Plugins
beim Laden ihre Hooks mit Formular-IDs registrieren. Zur Sicherheit im nutzenden Plugin:

```php
if ( ! class_exists( 'quform_map' ) && is_file( WP_PLUGIN_DIR . '/quform_map/includes/class-quform-map.php' ) ) {
	require_once WP_PLUGIN_DIR . '/quform_map/includes/class-quform-map.php';
}
```

## Umstellung eines bestehenden Plugins

1. Plugin aktivieren, unter *Einstellungen → Quform Felder* die bisherigen Optionen importieren.
2. Im Code die Zugriffe auf die Optionen (z. B. `$options['id_vorname']`) durch
   `quform_map::id( 'anmeldung', 'vorname' )` bzw. die passenden Helfer ersetzen.
3. Wenn alles umgestellt ist, die alten Einstellungen entfernen.
