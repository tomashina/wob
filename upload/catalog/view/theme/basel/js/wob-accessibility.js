(function (window, document) {
  'use strict';

  function text(value) {
    return (value || '').replace(/\s+/g, ' ').trim();
  }

  function labelInteractiveElements(root) {
    root.querySelectorAll('.tt-hint').forEach(function (input) {
      input.setAttribute('aria-hidden', 'true');
      input.setAttribute('tabindex', '-1');
    });

    root.querySelectorAll('input[type="text"], input[type="search"]').forEach(function (input) {
      if (!input.getAttribute('aria-label') && !input.id && input.placeholder) {
        input.setAttribute('aria-label', input.placeholder);
      }
    });

    root.querySelectorAll('a[href]').forEach(function (link) {
      if (!text(link.textContent) && !link.getAttribute('aria-label')) {
        var image = link.querySelector('img[alt]');
        var candidate = image ? (image.getAttribute('alt') || image.getAttribute('title')) : link.getAttribute('title');
        if (!text(candidate) && link.querySelector('.fa-home')) {
          candidate = document.documentElement.lang.indexOf('hr') === 0 ? 'Početna' : 'Home';
        }
        if (text(candidate)) {
          link.setAttribute('aria-label', text(candidate));
        }
      }
      if (link.target === '_blank') {
        link.rel = 'noopener noreferrer';
      }
    });

    root.querySelectorAll('a:not([href])').forEach(function (control) {
      if (control.getAttribute('role')) {
        return;
      }
      control.setAttribute('role', 'button');
      control.setAttribute('tabindex', '0');
      control.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          control.click();
        }
      });
    });
  }

  function stabilizeImages(root) {
    root.querySelectorAll('img').forEach(function (image) {
      function setDimensions() {
        if (!image.hasAttribute('width') && image.naturalWidth) {
          image.setAttribute('width', image.naturalWidth);
        }
        if (!image.hasAttribute('height') && image.naturalHeight) {
          image.setAttribute('height', image.naturalHeight);
        }
      }

      if (image.complete) {
        setDimensions();
      } else {
        image.addEventListener('load', setDimensions, { once: true });
      }
    });
  }

  function repairCarousels(root) {
    root.querySelectorAll('.slick-slider').forEach(function (slider, sliderIndex) {
      slider.setAttribute('role', 'region');
      slider.setAttribute('aria-roledescription', 'carousel');
      if (!slider.getAttribute('aria-label')) {
        slider.setAttribute('aria-label', 'Carousel ' + (sliderIndex + 1));
      }

      slider.querySelectorAll('[role="presentation"][aria-selected]').forEach(function (item) {
        item.removeAttribute('aria-selected');
      });
      slider.querySelectorAll('.slick-slide').forEach(function (slide, index) {
        slide.setAttribute('role', 'group');
        slide.setAttribute('aria-roledescription', 'slide');
        slide.setAttribute('aria-label', (index + 1) + ' / ' + slider.querySelectorAll('.slick-slide').length);
      });
      slider.querySelectorAll('.slick-dots').forEach(function (dots) {
        dots.removeAttribute('role');
        dots.removeAttribute('aria-label');
      });
    });
  }

  function enhance(root) {
    labelInteractiveElements(root);
    stabilizeImages(root);
    repairCarousels(root);
  }

  function ready() {
    enhance(document);
    if (window.jQuery) {
      window.jQuery(document).on('init reInit setPosition', '.slick-slider', function () {
        repairCarousels(document);
      });
    }

    if ('MutationObserver' in window) {
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) {
              enhance(node);
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
}(window, document));
