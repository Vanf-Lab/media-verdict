/**
 * Media Verdict — admin page interactions: batched scanner, bulk actions,
 * snapshots, protected list.
 */
(function ($) {
	'use strict';

	function selectedIds() {
		var ids = [];
		$('.mv-check:checked').each(function () {
			ids.push(parseInt($(this).val(), 10));
		});
		return ids;
	}

	function post(action, data, $target) {
		data = data || {};
		data.action = action;
		data.nonce = MediaVerdictAdmin.nonce;
		return $.post(MediaVerdictAdmin.ajax, data).done(function (res) {
			if ($target) {
				if (res && res.success) {
					$target.html('<div class="notice notice-success inline"><p>' + (res.data.message || 'OK') + '</p></div>');
				} else {
					$target.html('<div class="notice notice-error inline"><p>' + ((res && res.data && res.data.message) || 'Error') + '</p></div>');
				}
			}
		});
	}

	// ---- Scanner ---------------------------------------------------------
	$('#mv-scan-btn').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#mv-scan-progress').show();
		$('#mv-scan-bar').show();
		$('#mv-scan-fill').css('width', '0');

		post('media_verdict_scan_start', {}).done(function (res) {
			if (!res || !res.success) {
				$btn.prop('disabled', false);
				return;
			}
			var phases = res.data.phases;
			runBatch(0, 0, phases);
		});

		function runBatch(phase, offset, phases) {
			$('#mv-scan-label').text(MediaVerdictAdmin.i18n.scanning + ' ' + (phases[phase] || ''));
			post('media_verdict_scan_batch', { phase: phase, offset: offset }).done(function (res) {
				if (!res || !res.success) {
					$btn.prop('disabled', false);
					$('#mv-scan-label').text('Error');
					return;
				}
				var d = res.data;
				if (d.finished) {
					$('#mv-scan-fill').css('width', '100%');
					$('#mv-scan-label').text(MediaVerdictAdmin.i18n.done);
					setTimeout(function () { location.reload(); }, 800);
					return;
				}
				var pct = Math.round(((d.phase + (d.offset > 0 ? 0.5 : 0)) / phases.length) * 100);
				$('#mv-scan-fill').css('width', Math.min(99, pct) + '%');
				$('#mv-scan-label').text(d.label || '');
				runBatch(d.phase, d.offset, phases);
			});
		}
	});

	// ---- Table ------------------------------------------------------------
	$('#mv-check-all').on('change', function () {
		$('.mv-check').prop('checked', $(this).prop('checked'));
	});

	$(document).on('click', '.mv-evidence-toggle', function (e) {
		e.preventDefault();
		$(this).siblings('.mv-evidence-full').toggle();
	});

	function needSelection() {
		var ids = selectedIds();
		if (!ids.length) {
			alert(MediaVerdictAdmin.i18n.selectFirst);
			return null;
		}
		return ids;
	}

	$('#mv-bulk-snapshot').on('click', function () {
		var ids = needSelection();
		if (!ids) return;
		post('media_verdict_bulk_snapshot', { ids: ids }, $('#mv-bulk-result'));
	});

	$('#mv-bulk-dryrun').on('click', function () {
		var ids = needSelection();
		if (!ids) return;
		var $t = $('#mv-bulk-result');
		post('media_verdict_trash_dryrun', { ids: ids }).done(function (res) {
			if (res && res.success) {
				var html = '<div class="notice notice-info inline"><p><strong>' + res.data.message + '</strong></p><ul>';
				$.each(res.data.items, function (_, it) {
					html += '<li>#' + it.id + ' ' + $('<div>').text(it.title).html() + ' — ' + it.files + ' archivos</li>';
				});
				$t.html(html + '</ul></div>');
			} else {
				$t.html('<div class="notice notice-error inline"><p>' + ((res && res.data && res.data.message) || 'Error') + '</p></div>');
			}
		});
	});

	$('#mv-bulk-trash').on('click', function () {
		var ids = needSelection();
		if (!ids) return;
		if (!confirm(MediaVerdictAdmin.i18n.confirmTrash)) return;
		post('media_verdict_bulk_trash', { ids: ids }, $('#mv-bulk-result')).done(function () {
			setTimeout(function () { location.reload(); }, 1500);
		});
	});

	$('#mv-bulk-protect').on('click', function () {
		var ids = needSelection();
		if (!ids) return;
		post('media_verdict_protect', { ids: ids }, $('#mv-bulk-result'));
	});

	// ---- Snapshots ----------------------------------------------------------
	$(document).on('click', '.mv-snap-restore', function () {
		var file = $(this).data('file');
		post('media_verdict_snapshot_restore', { file: file }, $('#mv-snap-result'));
	});

	$(document).on('click', '.mv-snap-delete', function () {
		var file = $(this).data('file');
		if (!confirm(file + ' — ¿eliminar snapshot?')) return;
		var $row = $(this).closest('tr');
		post('media_verdict_snapshot_delete', { file: file }, $('#mv-snap-result')).done(function (res) {
			if (res && res.success) $row.fadeOut();
		});
	});

	$('#mv-prune-snapshots').on('click', function () {
		post('media_verdict_prune_snapshots', {}, $('#mv-prune-result'));
	});

	// ---- Protected -----------------------------------------------------------
	$(document).on('click', '.mv-unprotect', function () {
		var id = $(this).data('id');
		var $row = $(this).closest('tr');
		post('media_verdict_protect', { ids: [id], unprotect: 1 }, $('#mv-protect-result')).done(function (res) {
			if (res && res.success) $row.fadeOut();
		});
	});
})(jQuery);
