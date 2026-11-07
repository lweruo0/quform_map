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
			if ($(this).find('.qf-del').is(':checked')) {
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

	$('.qf-main').on('change', '.qf-del', function () {
		gruppe_aktualisieren($(this).closest('.qf-group'));
	});

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
