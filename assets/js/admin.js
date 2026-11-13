/**
 * Backend „Quform Felder“: Feldauswahl beim Formularwechsel neu aufbauen, Zeilen hinzufügen,
 * Schlüssel aus der Feldbezeichnung vorschlagen, Quform-Namen anzeigen.
 */
jQuery(document).ready(function ($) {
	if (typeof quformMapAdmin === 'undefined') {
		return;
	}
	const daten = quformMapAdmin;
	let zaehler = 0;

	const formular_id = function ($gruppe) {
		return parseInt($gruppe.find('.qf-form').first().val(), 10) || 0;
	};

	/* Schlüssel aus einer Bezeichnung: „Straße & Nr.“ → strasse_nr */
	const schluessel = function (text) {
		let s = String(text || '')
			.replace(/^.*›\s*/, '')
			.replace(/\(.*\)\s*$/, '')
			.toLowerCase()
			.replace(/ä/g, 'ae')
			.replace(/ö/g, 'oe')
			.replace(/ü/g, 'ue')
			.replace(/ß/g, 'ss')
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '');
		if (/^[0-9]/.test(s)) {
			s = 'f_' + s;
		}
		return s.slice(0, 64);
	};

	const name_anzeigen = function ($zeile, form_id) {
		const id = parseInt($zeile.find('.qf-element').val(), 10) || 0;
		$zeile.find('.qf-name').text(form_id > 0 && id > 0 ? 'quform_' + form_id + '_' + id : '');
	};

	/* Optionen einer Feldauswahl für ein Formular neu aufbauen, gewählten Wert behalten.
	   Elemente aus belegt (in anderen Zeilen der Gruppe zugeordnet) werden nicht angeboten. */
	const auswahl_fuellen = function ($select, form_id, belegt) {
		if (!$select.is('select')) {
			return;
		}
		const aktuell = parseInt($select.val(), 10) || 0;
		const formular = daten.forms[form_id];
		const elemente = formular ? formular.elements : [];
		let gefunden = false;

		$select.empty().append($('<option></option>').val('').text(daten.choose));
		elemente.forEach(function (e) {
			if (e[0] === aktuell) {
				gefunden = true;
			} else if (belegt.indexOf(e[0]) !== -1) {
				return;
			}
			const $o = $('<option></option>').val(e[0]).text('#' + e[0] + ' ' + e[1] + ' (' + e[2] + ')');
			if (e[0] === aktuell) {
				$o.prop('selected', true);
			}
			$select.append($o);
		});
		if (aktuell > 0 && !gefunden) {
			$select.append($('<option></option>').val(aktuell).text('#' + aktuell + ' – ' + daten.missing).prop('selected', true));
		}
		$select.closest('tr').toggleClass('qf-missing', aktuell > 0 && !gefunden);
	};

	/* Alle Feldauswahlen einer Gruppe neu aufbauen: jede Auswahl bietet nur Elemente an, die in
	   keiner anderen (nicht zum Löschen markierten) Zeile der Gruppe zugeordnet sind. */
	const gruppe_aktualisieren = function ($gruppe) {
		const form_id = formular_id($gruppe);
		const $zeilen = $gruppe.find('.qf-fields tbody tr.qf-row');
		const ids = $zeilen.map(function () {
			if ($(this).find('.qf-del').val() === '1') {
				return 0;
			}
			return parseInt($(this).find('.qf-element').val(), 10) || 0;
		}).get();
		$zeilen.each(function (i) {
			const belegt = ids.filter(function (id, j) {
				return j !== i && id > 0;
			});
			auswahl_fuellen($(this).find('.qf-element'), form_id, belegt);
			name_anzeigen($(this), form_id);
		});
	};

	$('.qf-main').on('change', '.qf-form', function () {
		gruppe_aktualisieren($(this).closest('.qf-group'));
	});

	/* Löschen-Button umschalten: markiert (hidden delete=1) oder zurück; Beschriftung tauschen */
	const loeschen_umschalten = function ($button, $ziel, klasse) {
		const $feld = $ziel.find('.qf-del').first();
		const geloescht = $feld.val() !== '1';
		$feld.val(geloescht ? '1' : '0');
		$ziel.toggleClass(klasse, geloescht);
		if (!$button.data('label')) {
			$button.data('label', $button.attr('title') || $button.text());
		}
		const text = geloescht ? $button.data('undo') : $button.data('label');
		if ($button.find('.dashicons').length) {
			$button.attr('title', text).find('.screen-reader-text').text(text);
			$button.find('.dashicons').toggleClass('dashicons-trash', !geloescht).toggleClass('dashicons-undo', geloescht);
		} else {
			$button.text(text);
		}
		return geloescht;
	};

	$('.qf-main').on('click', '.qf-row-del', function () {
		const $zeile = $(this).closest('tr');
		const $gruppe = $zeile.closest('.qf-group');
		if (!$zeile.data('saved')) {
			// noch nicht gespeicherte Zeile: direkt entfernen
			$zeile.remove();
		} else {
			// gesperrte Felder werden nicht validiert und nicht gesendet, delete=1 schon
			const geloescht = loeschen_umschalten($(this), $zeile, 'qf-deleted');
			$zeile.find('.qf-key, .qf-element').prop('disabled', geloescht);
		}
		gruppe_aktualisieren($gruppe);
	});

	$('.qf-main').on('click', '.qf-group-del', function () {
		const $gruppe = $(this).closest('.qf-group');
		const geloescht = loeschen_umschalten($(this), $gruppe, 'qf-group-deleted');
		// Felder der Gruppe sperren/entsperren (nicht validieren), Lösch-Markierungen der Zeilen bleiben erhalten
		$gruppe.find('.qf-head').find('input[type="text"], .qf-form').prop('disabled', geloescht);
		$gruppe.find('.qf-row').each(function () {
			$(this).find('.qf-key, .qf-element').prop('disabled', geloescht || $(this).hasClass('qf-deleted'));
			$(this).find('.qf-row-del').prop('disabled', geloescht);
		});
		$gruppe.find('.qf-add').prop('disabled', geloescht);
	});

	/* Auf-/Zuklappen: Zustand je Gruppenschlüssel im Browser merken */
	const speicher = 'quformMapOffen';
	const offen_lesen = function () {
		try {
			return JSON.parse(window.localStorage.getItem(speicher) || 'null');
		} catch (e) {
			return null;
		}
	};
	const offen_schreiben = function () {
		const keys = $('.qf-main details.qf-group[open]').map(function () {
			return String($(this).data('key'));
		}).get();
		try {
			window.localStorage.setItem(speicher, JSON.stringify(keys));
		} catch (e) {
			// Speicher nicht verfügbar: Zustand nur für diese Seite
		}
	};

	const gespeichert = offen_lesen();
	if ($('.qf-main').data('restore') && Array.isArray(gespeichert)) {
		$('.qf-main details.qf-group').each(function () {
			// Gruppen mit Warnung (fehlende Felder) bleiben offen
			if (gespeichert.indexOf(String($(this).data('key'))) !== -1) {
				this.open = true;
			} else if (!$(this).find('.qf-badge').not('.qf-badge-del').length) {
				this.open = false;
			}
		});
	}
	$('.qf-main details.qf-group').on('toggle', offen_schreiben);

	$('.qf-open-all, .qf-close-all').on('click', function () {
		const auf = $(this).hasClass('qf-open-all');
		$('.qf-main details.qf-group').each(function () {
			this.open = auf;
		});
		offen_schreiben();
	});

	/* Ungültiges Feld in einer zugeklappten Gruppe: Gruppe öffnen, damit der Browser es anzeigen kann */
	const form = $('.qf-main').get(0);
	if (form) {
		form.addEventListener('invalid', function (e) {
			const details = $(e.target).closest('details').get(0);
			if (details && !details.open) {
				details.open = true;
			}
		}, true);
	}

	$('.qf-main').on('change', '.qf-element', function () {
		const $zeile = $(this).closest('tr');
		const $key = $zeile.find('.qf-key');
		if ($.trim($key.val()) === '' && $(this).is('select') && $(this).val() !== '') {
			$key.val(schluessel($(this).find('option:selected').text().replace(/^#\d+\s*/, '')));
		}
		gruppe_aktualisieren($zeile.closest('.qf-group'));
	});

	$('.qf-main').on('click', '.qf-add', function () {
		const $gruppe = $(this).closest('.qf-group');
		const vorlage = $gruppe.find('template.qf-template').get(0);
		if (!vorlage) {
			return;
		}
		zaehler += 1;
		const html = vorlage.innerHTML.replace(/__F__/g, 'n' + Date.now() + '_' + zaehler);
		const $zeile = $(html);
		$gruppe.find('.qf-fields tbody').append($zeile);
		gruppe_aktualisieren($gruppe);
		$zeile.find('.qf-key').trigger('focus');
	});

	/* Beim Laden: bereits zugeordnete Elemente aus den anderen Auswahlen entfernen */
	$('.qf-main .qf-group').each(function () {
		gruppe_aktualisieren($(this));
	});

	/* Import: Gruppenschlüssel aus der gewählten Option vorschlagen */
	$('.qf-import-option').on('change', function () {
		$('.qf-import-group').val($(this).find('option:selected').data('group') || '');
	});
});
