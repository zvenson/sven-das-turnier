jQuery(function ($) {
	'use strict';

	var $wrap = $('.sdt-wrap');
	if (!$wrap.length) return;
	var tid = $wrap.data('tid');

	function post(action, data) {
		data = $.extend({ action: action, nonce: SDT.nonce }, data);
		return $.post(SDT.ajaxurl, data);
	}

	function refreshCounts() {
		$('.sdt-droplist').each(function () {
			var $list = $(this);
			$list.closest('.sdt-unassigned, .sdt-group').find('.sdt-count').text($list.children('.sdt-player').length);
		});
	}

	function playerLi(id, name) {
		var $li = $('<li class="sdt-player">')
			.attr('data-pid', id)
			.append('<span class="sdt-handle">⋮⋮</span>')
			.append($('<span class="sdt-name">').text(name))
			.append('<button type="button" class="sdt-del-player" title="Spieler löschen">×</button>');
		return $li;
	}

	// --- Drag & Drop ---
	if ($('.sdt-connected').length) {
		$('.sdt-connected').sortable({
			connectWith: '.sdt-connected',
			handle: '.sdt-handle',
			placeholder: 'sdt-placeholder',
			tolerance: 'pointer',
			receive: function (event, ui) {
				var $item = ui.item;
				var $list = $(this);

				// Reg-Item in Reg-Liste fallen lassen: nicht erlaubt → revert
				if ($list.hasClass('sdt-reg-list') && !$item.hasClass('sdt-reg-item')) {
					ui.sender.sortable('cancel');
					return;
				}

				// Reg-Item irgendwo eingelassen → AJAX-Import, DOM tauschen
				if ($item.hasClass('sdt-reg-item')) {
					var rid   = $item.data('rid');
					var group = $list.data('group') || '';
					if (group === '__reg__') return;
					var pos   = $list.children().index($item);
					$item.addClass('sdt-importing');
					post('sdt_import_registration', {
						tournament_id: tid,
						registration_id: rid,
						group_label: group,
						position: pos
					}).done(function (res) {
						if (res && res.success) {
							var $new = playerLi(res.data.id, res.data.name);
							$item.replaceWith($new);
							refreshCounts();
						}
					});
				}
			},
			update: function (event, ui) {
				if (this !== ui.item.parent()[0]) return;
				var $item = ui.item;
				if (!$item.hasClass('sdt-player')) return; // reg-items handled in receive
				var $list = $item.parent();
				var group = $list.data('group') || '';
				if (group === '__reg__') return;
				var pos   = $list.children('.sdt-player').index($item);
				post('sdt_assign_player', {
					player_id: $item.data('pid'),
					group_label: group,
					position: pos
				}).done(refreshCounts);
			}
		}).disableSelection();
	}

	// --- Spieler hinzufügen ---
	$(document).on('submit', '.sdt-add-player-form', function (e) {
		e.preventDefault();
		var $f    = $(this);
		var $in   = $f.find('.sdt-new-name');
		var name  = $.trim($in.val());
		if (!name) return;
		post('sdt_add_player', { tournament_id: tid, name: name }).done(function (res) {
			if (!res.success) return;
			var html = '<li class="sdt-player" data-pid="' + res.data.id + '">'
				+ '<span class="sdt-handle">⋮⋮</span>'
				+ '<span class="sdt-name"></span>'
				+ '<button type="button" class="sdt-del-player" title="Spieler löschen">×</button>'
				+ '</li>';
			var $li = $(html);
			$li.find('.sdt-name').text(res.data.name);
			$('.sdt-unassigned .sdt-droplist').append($li);
			$in.val('').focus();
			refreshCounts();
		});
	});

	// --- Spieler löschen ---
	$(document).on('click', '.sdt-del-player', function () {
		if (!confirm('Spieler entfernen?')) return;
		var $li = $(this).closest('.sdt-player');
		post('sdt_delete_player', { player_id: $li.data('pid') }).done(function () {
			$li.remove();
			refreshCounts();
		});
	});

	// --- Plan generieren ---
	$(document).on('click', '.sdt-generate', function () {
		if (!confirm('Plan generieren und Turnier starten? Die Gruppen-Zuordnung wird festgeschrieben.')) return;
		var $btn = $(this).prop('disabled', true).text('Generiere…');
		post('sdt_generate_plan', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('Plan generieren & Turnier starten');
		});
	});

	// --- Tennis: Ergebnis-Dialog ---
	var bestOf = parseInt($wrap.data('bestof'), 10) || 3;

	function closeResultModal() {
		$('.sdt-modal-overlay').remove();
		$(document).off('keydown.sdtmodal');
	}

	function openResultModal(mid, p1id, p1name, p2id, p2name) {
		closeResultModal();

		var $overlay = $('<div class="sdt-modal-overlay">');
		var $modal   = $('<div class="sdt-modal">');
		$modal.append($('<h2>').text(p1name + ' vs. ' + p2name));

		// Satz-Eingaben
		var $sets = $('<div class="sdt-modal-sets">');
		var $head = $('<div class="sdt-modal-setrow sdt-modal-sethead">')
			.append('<span></span>')
			.append($('<span class="sdt-modal-pname">').text(p1name))
			.append('<span></span>')
			.append($('<span class="sdt-modal-pname">').text(p2name));
		$sets.append($head);
		for (var i = 1; i <= bestOf; i++) {
			var $row = $('<div class="sdt-modal-setrow">')
				.append($('<label>').text('Satz ' + i))
				.append('<input type="number" min="0" max="99" class="sdt-set-p1" inputmode="numeric">')
				.append('<span class="sdt-modal-colon">:</span>')
				.append('<input type="number" min="0" max="99" class="sdt-set-p2" inputmode="numeric">');
			$sets.append($row);
		}
		$modal.append($sets);

		// Walkover
		var $wo = $('<div class="sdt-modal-wo">');
		$wo.append('<label class="sdt-wo-toggle"><input type="checkbox" class="sdt-wo-check"> Nicht angetreten (w.o.)</label>');
		var $woWinner = $('<div class="sdt-wo-winner" style="display:none;">')
			.append('<p>Wer gewinnt kampflos?</p>')
			.append($('<label>').append('<input type="radio" name="sdt-wo-w" value="' + p1id + '"> ').append(document.createTextNode(p1name)))
			.append($('<label>').append('<input type="radio" name="sdt-wo-w" value="' + p2id + '"> ').append(document.createTextNode(p2name)));
		$wo.append($woWinner);
		$modal.append($wo);

		var $err = $('<div class="sdt-modal-error" style="display:none;">');
		$modal.append($err);

		var $btns = $('<div class="sdt-modal-buttons">')
			.append('<button type="button" class="button button-primary sdt-modal-save">Speichern</button> ')
			.append('<button type="button" class="button sdt-modal-cancel">Abbrechen</button>');
		$modal.append($btns);

		$overlay.append($modal).appendTo('body');
		$sets.find('.sdt-set-p1').first().trigger('focus');

		$overlay.on('change', '.sdt-wo-check', function () {
			var wo = this.checked;
			$sets.toggle(!wo);
			$woWinner.toggle(wo);
		});

		$overlay.on('click', function (e) {
			if (e.target === $overlay[0]) closeResultModal();
		});
		$overlay.on('click', '.sdt-modal-cancel', closeResultModal);
		$(document).on('keydown.sdtmodal', function (e) {
			if (e.key === 'Escape') closeResultModal();
		});

		$overlay.on('click', '.sdt-modal-save', function () {
			var $btn = $(this);
			var wo   = $overlay.find('.sdt-wo-check').is(':checked');
			var data = { match_id: mid, walkover: wo ? 1 : 0 };

			if (wo) {
				var wid = $overlay.find('input[name="sdt-wo-w"]:checked').val();
				if (!wid) {
					$err.text('Bitte auswählen, wer kampflos gewinnt.').show();
					return;
				}
				data.winner_id = wid;
			} else {
				var sets = [];
				var bad  = false;
				$sets.find('.sdt-modal-setrow').not('.sdt-modal-sethead').each(function () {
					var a = $(this).find('.sdt-set-p1').val();
					var b = $(this).find('.sdt-set-p2').val();
					if (a === '' && b === '') return; // leerer Satz = ignorieren
					if (a === '' || b === '') { bad = true; return; }
					sets.push([parseInt(a, 10), parseInt(b, 10)]);
				});
				if (bad) {
					$err.text('Ein Satz ist nur halb ausgefüllt.').show();
					return;
				}
				if (!sets.length) {
					$err.text('Bitte mindestens einen Satz eintragen.').show();
					return;
				}
				data.sets = JSON.stringify(sets);
			}

			$btn.prop('disabled', true);
			post('sdt_set_result', data).done(function (res) {
				if (res && res.success) {
					location.reload();
				} else {
					$btn.prop('disabled', false);
					$err.text(res && res.data && res.data.message ? res.data.message : 'Fehler beim Speichern.').show();
				}
			}).fail(function () {
				$btn.prop('disabled', false);
				$err.text('Fehler beim Speichern.').show();
			});
		});
	}

	$(document).on('click', '.sdt-result', function () {
		var $btn = $(this);
		var mid  = $btn.data('mid') || $btn.closest('[data-mid]').data('mid');
		openResultModal(mid, $btn.data('p1'), String($btn.data('p1name')), $btn.data('p2'), String($btn.data('p2name')));
	});

	// --- Sieg eintragen ---
	$(document).on('click', '.sdt-win', function () {
		var $btn = $(this);
		var mid  = $btn.closest('[data-mid]').data('mid');
		var wid  = $btn.data('winner');
		$btn.prop('disabled', true);
		post('sdt_set_winner', { match_id: mid, winner_id: wid }).done(function () {
			location.reload();
		});
	});

	// --- 30 Demo-Spieler ---
	$(document).on('click', '.sdt-demo', function () {
		if (!confirm('30 Demo-Spieler hinzufügen?')) return;
		var $btn = $(this).prop('disabled', true).text('Lege an…');
		post('sdt_demo_players', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('30 Demo-Spieler anlegen');
		});
	});

	// --- Alle Spieler löschen ---
	$(document).on('click', '.sdt-delete-all', function () {
		if (!confirm('Wirklich ALLE Spieler dieses Turniers löschen?')) return;
		var $btn = $(this).prop('disabled', true).text('Lösche…');
		post('sdt_delete_all_players', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('Alle Spieler löschen');
		});
	});

	// --- Match zurücksetzen ---
	$(document).on('click', '.sdt-reset', function () {
		if (!confirm('Ergebnis zurücksetzen?')) return;
		var $btn = $(this);
		var $row = $btn.closest('[data-mid]');
		var mid  = $row.data('mid');
		post('sdt_reset_match', { match_id: mid }).done(function () {
			location.reload();
		});
	});

	// --- Vorrunde simulieren ---
	$(document).on('click', '.sdt-simulate-groups', function () {
		if (!confirm('Alle offenen Vorrunden-Spiele mit Zufalls-Siegern füllen?')) return;
		var $btn = $(this).prop('disabled', true).text('Simuliere…');
		post('sdt_simulate_groups', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('🎲 Vorrunde simulieren');
		});
	});

	// --- Brackets simulieren ---
	$(document).on('click', '.sdt-simulate-brackets', function () {
		if (!confirm('Alle restlichen Bracket-Spiele zufällig durchsimulieren?')) return;
		var $btn = $(this).prop('disabled', true).text('Simuliere…');
		post('sdt_simulate_brackets', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('🎲 Brackets simulieren (Zufalls-Ergebnisse)');
		});
	});

	// --- Brackets generieren ---
	$(document).on('click', '.sdt-generate-brackets', function () {
		var $btn = $(this).prop('disabled', true).text('Generiere…');
		post('sdt_generate_brackets', { tournament_id: tid }).done(function () {
			location.reload();
		}).fail(function () {
			$btn.prop('disabled', false).text('🏆 Gold- und Silberrunde generieren');
		});
	});

	// --- Brackets zurücksetzen ---
	$(document).on('click', '.sdt-reset-brackets', function () {
		if (!confirm('Beide Brackets komplett zurücksetzen?')) return;
		post('sdt_reset_brackets', { tournament_id: tid }).done(function () {
			location.reload();
		});
	});
});
