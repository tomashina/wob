import PhotoSwipeLightbox from './photoswipe-lightbox.esm.min.js';

const gallery = document.querySelector('.product-gallery-stage');

if (gallery) {
  const lightbox = new PhotoSwipeLightbox({
    gallery: '.product-gallery-stage',
    children: '.product-gallery-slide:not(.slick-cloned) .product-gallery-link',
    pswpModule: () => import('./photoswipe.esm.min.js'),
    bgOpacity: 0.96,
    showHideAnimationType: 'zoom',
    imageClickAction: 'zoom-or-close',
    tapAction: 'zoom-or-close',
    secondaryZoomLevel: 2,
    maxZoomLevel: 4,
    wheelToZoom: true,
    paddingFn: (viewportSize) => ({
      top: viewportSize.x < 768 ? 64 : 76,
      bottom: viewportSize.x < 768 ? 66 : 78,
      left: viewportSize.x < 768 ? 12 : 42,
      right: viewportSize.x < 768 ? 12 : 42
    })
  });

  lightbox.on('uiRegister', () => {
    lightbox.pswp.ui.registerElement({
      name: 'product-caption',
      order: 9,
      isButton: false,
      appendTo: 'root',
      html: '',
      onInit: (element, pswp) => {
        const updateCaption = () => {
          const image = pswp.currSlide?.data?.element?.querySelector('img');
          element.textContent = image?.alt || '';
        };

        pswp.on('change', updateCaption);
        updateCaption();
      }
    });
  });

  lightbox.init();
}
