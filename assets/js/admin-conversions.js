(function ($) {
	'use strict';

	function toggleCustom($row) {
		var $sel = $row.find('.marq-event-select');
		var $custom = $row.find('.marq-event-custom');
		if ($sel.val() === '__custom__') {
			$custom.show();
		} else {
			$custom.hide();
		}
	}

	$(function () {
		$('#marq-conversions-rules').on('change', '.marq-event-select', function () {
			toggleCustom($(this).closest('tr'));
		});

		$('#marq-conversions-rules tr.marq-conversions-rule-row').each(function () {
			toggleCustom($(this));
		});

		var $tbody = $('#marq-conversions-rules tbody');
		var nextIndex = parseInt($tbody.data('next-index'), 10) || 0;

		$('#marq-conversions-add-row').on('click', function () {
			var $clone = $('#marq-conversions-rule-template tr').first().clone(true, true);
			$clone.find('[name]').each(function () {
				this.name = this.name.replace(/__INDEX__/g, String(nextIndex));
				this.id = (this.id || '').replace(/__INDEX__/g, String(nextIndex));
			});
			$tbody.append($clone);
			toggleCustom($clone);
			nextIndex += 1;
		});
	});
})(jQuery);
