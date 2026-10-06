/**
 * Media Verdict — library grid/list overlays (upload.php only).
 *
 * Grid mode: green frame = used, red frame + badge = no detected usage.
 * Tooltip (title) and click show the evidence summary.
 * List mode is rendered server-side (PHP column); this file only handles
 * the grid. Scoped to the upload.php screen — never touches the editor modal.
 */
(function ($) {
	'use strict';

	var fetched = {};   // id -> {status, evidence}
	var pending = {};   // ids waiting for AJAX
	var timer = null;

	function badgeText(status) {
		if (status === 'used') return MediaVerdictLibrary.i18n.used;
		if (status === 'unused') return MediaVerdictLibrary.i18n.unused;
		return MediaVerdictLibrary.i18n.notScanned;
	}

	function applyToItem($item, id) {
		var data = fetched[id];
		if (!data) return;
		$item.removeClass('mv-used mv-unused mv-unknown');
		$item.addClass('mv-' + data.status);
		$item.find('.mv-badge').remove();
		var $badge = $('<span class="mv-badge mv-badge-' + data.status + '"></span>').text(badgeText(data.status));
		$item.append($badge);
		if (data.evidence) {
			$item.attr('title', MediaVerdictLibrary.i18n.evidence + ': ' + data.evidence);
		}
		// Click on the badge opens the detail view in Media Verdict.
		$badge.off('click.mv').on('click.mv', function (e) {
			e.stopPropagation();
			window.location.href = MediaVerdictLibrary.admin + '&media_verdict_focus=' + id;
		});
	}

	function collectVisible() {
		var ids = [];
		$('.attachments .attachment[data-id]').each(function () {
			var id = parseInt($(this).attr('data-id'), 10);
			if (id && !fetched[id] && !pending[id]) {
				pending[id] = true;
				ids.push(id);
			}
		});
		return ids;
	}

	function flush() {
		var ids = collectVisible();
		if (!ids.length) return;
		$.post(MediaVerdictLibrary.ajax, {
			action: 'media_verdict_verdicts',
			nonce: MediaVerdictLibrary.nonce,
			ids: ids
		}).done(function (res) {
			if (res && res.success && res.data) {
				$.each(res.data, function (id, v) {
					fetched[id] = v;
					delete pending[id];
					var $item = $('.attachments .attachment[data-id="' + id + '"]');
					if ($item.length) applyToItem($item, id);
				});
			}
		}).fail(function () {
			$.each(ids, function (_, id) { delete pending[id]; });
		});
	}

	function schedule() {
		if (timer) return;
		timer = setTimeout(function () {
			timer = null;
			flush();
		}, 250);
	}

	$(function () {
		if (!$('.attachments').length) return;

		// First paint: also try reading data embedded by wp_prepare_attachment_for_js.
		// (Backbone models are not directly accessible here, so we rely on AJAX.)
		schedule();

		var obs = new MutationObserver(function () { schedule(); });
		var target = document.querySelector('.attachments');
		if (target) {
			obs.observe(target, { childList: true, subtree: true });
		}

		// Re-scan when switching between grid/list or paginating.
		$(document).on('click', '.tablenav-pages a, .view-switch a', function () {
			setTimeout(schedule, 600);
		});
	});
})(jQuery);
