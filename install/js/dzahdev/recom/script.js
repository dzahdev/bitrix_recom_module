(function () {
	'use strict';

	window.DzahdevRecom = window.DzahdevRecom || {
		/**
		 * Учет просмотра товара для шаблонов, которые не вызывают стандартный учет просмотров каталога.
		 * @param {number} productId ID товара или торгового предложения
		 * @returns {Promise}
		 */
		trackView: function (productId) {
			return BX.ajax.runAction('dzahdev:recom.Recommend.trackView', {
				data: { productId: productId }
			});
		}
	};
})();
