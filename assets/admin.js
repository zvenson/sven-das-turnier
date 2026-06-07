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

	// --- Drag & Drop ---
	if ($('.sdt-connected').length) {
		$('.sdt-connected').sortable({
			connectWith: '.sdt-connected',
			handle: '.sdt-handle',
			placeholder: 'sdt-placeholder',
			tolerance: 'pointer',
			update: function (event, ui) {
				if (this !== ui.item.parent()[0]) return;
				var $item = ui.item;
				var $list = $item.parent();
				var group = $list.data('group') || '';
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
		var mid  = $btn.closest('tr').data('mid');
		var wid  = $btn.data('winner');
		$btn.prop('disabled', true);
		post('sdt_set_winner', { match_id: mid, winner_id: wid }).done(function () {
			location.reload();
		});
	});

	// --- Match zurücksetzen ---
	$(document).on('click', '.sdt-reset', function () {
		if (!confirm('Ergebnis zurücksetzen?')) return;
		var mid = $(this).closest('tr').data('mid');
		post('sdt_reset_match', { match_id: mid }).done(function () {
			location.reload();
		});
	});
});
