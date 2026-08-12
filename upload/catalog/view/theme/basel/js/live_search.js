(function ($) {
	'use strict';

	var selector = '.header-wrapper input[name="search"]';

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function resultsMarkup() {
		return '<div class="live-search" role="region" aria-live="polite" aria-label="Rezultati pretraživanja">' +
			'<table class="table products"><tbody></tbody></table>' +
			'<div class="result-text"></div>' +
			'</div>';
	}

	function positionResults($input, $results) {
		if (window.matchMedia('(max-width: 991px)').matches) {
			var wrapper = $input.closest('.full-search-wrapper')[0] || $input[0];
			var top = Math.max(0, Math.round(wrapper.getBoundingClientRect().bottom));
			$results[0].style.setProperty('--wob-live-search-top', top + 'px');
		} else {
			$results[0].style.removeProperty('--wob-live-search-top');
		}
	}

	function openResults($input, $results) {
		positionResults($input, $results);
		$results.addClass('is-open').show();
	}

	function renderProducts($input, $results, result, query) {
		var products = result.products || [];
		var rows = '';

		$.each(products, function (index, product) {
			var url = escapeHtml(product.url);
			rows += '<tr>';

			if (product.image) {
				rows += '<td class="image"><a href="' + url + '" aria-label="' + escapeHtml(product.name) + '">' +
					'<img alt="" src="' + escapeHtml(product.image) + '"></a></td>';
				rows += '<td class="main">';
			} else {
				rows += '<td colspan="2" class="main">';
			}

			rows += '<a href="' + url + '" class="product-name main-font">' + escapeHtml(product.name) + '</a>';

			if (product.special) {
				rows += '<div class="price"><span class="price-old">' + escapeHtml(product.price) +
					'</span><span class="price">' + escapeHtml(product.special) + '</span></div>';
			} else if (product.price) {
				rows += '<div class="price"><span class="price">' + escapeHtml(product.price) + '</span></div>';
			}

			rows += '</td></tr>';
		});

		$results.find('.table.products tbody').html(rows);

		if (products.length) {
			var searchUrl = String(result.search_url || 'index.php?route=product/search').replace(/&amp;/g, '&');
			$results.find('.result-text').html(
				'<a href="' + escapeHtml(searchUrl + '&search=' + encodeURIComponent(query)) +
				'" class="view-all-results">' + escapeHtml(result.basel_text_view_all) +
				' (' + escapeHtml(result.total || products.length) + ')</a>'
			);
		} else {
			$results.find('.result-text').text(result.basel_text_no_result || 'Nema rezultata.');
		}

		openResults($input, $results);
	}

	$(function () {
		$(selector).each(function () {
			var $input = $(this);

			// Mega Smart Search owns inputs wrapped by its typeahead instance.
			if ($input.closest('.msmart-search-live-filter').length) {
				return;
			}

			var timer = null;
			var request = null;

			if (!$input.next('.live-search').length) {
				$input.after(resultsMarkup());
			}

			var $results = $input.next('.live-search');

			function search() {
				var query = $.trim($input.val());

				clearTimeout(timer);
				if (request) {
					request.abort();
					request = null;
				}

				if (query.length < 2) {
					$results.removeClass('is-open').hide();
					return;
				}

				timer = setTimeout(function () {
					$results.find('.table.products tbody').html(
						'<tr class="live-search-loading"><td><span class="basel-spinner" aria-label="Pretraživanje"></span></td></tr>'
					);
					$results.find('.result-text').empty();
					openResults($input, $results);

					request = $.ajax({
						url: 'index.php?route=extension/basel/live_search&filter_name=' + encodeURIComponent(query),
						dataType: 'json',
						type: 'get'
					}).done(function (result) {
						if ($.trim($input.val()) === query) {
							renderProducts($input, $results, result, query);
						}
					}).fail(function (xhr, status) {
						if (status !== 'abort') {
							$results.removeClass('is-open').hide();
						}
					}).always(function () {
						request = null;
					});
				}, 180);
			}

			$input.on('input.liveSearch', search);
			$input.on('focus.liveSearch', search);
			$input.on('keydown.liveSearch', function (event) {
				if (event.keyCode === 27) {
					$results.removeClass('is-open').hide();
				}
			});
		});

		$(window).on('resize.liveSearch scroll.liveSearch', function () {
			$(selector).each(function () {
				var $input = $(this);
				var $results = $input.next('.live-search');

				if ($results.hasClass('is-open')) {
					positionResults($input, $results);
				}
			});
		});

		$(document).on('mousedown.liveSearch touchstart.liveSearch', function (event) {
			if (!$(event.target).closest('.search-field').length) {
				$('.live-search').removeClass('is-open').hide();
			}
		});
	});
}(jQuery));
