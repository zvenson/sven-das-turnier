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
