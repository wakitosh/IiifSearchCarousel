(() => {
  const init = () => {
    const sections = document.querySelectorAll('.iiif-sc');
    sections.forEach(section => {
      const slides = Array.from(section.querySelectorAll('.iiif-sc__slide'));
      if (slides.length <= 1) return;

      const dots = Array.from(section.querySelectorAll('.iiif-sc__dot'));
      let i = 0;
      const dur = (window.IIIF_SC_DURATION || 6000);
      let timerId = null;

      const setActive = (next) => {
        const prev = i;
        if (next === prev) return;
        if (slides[prev]) slides[prev].classList.remove('is-active');
        if (dots[prev]) {
          dots[prev].classList.remove('is-active');
          dots[prev].setAttribute('aria-selected', 'false');
        }
        i = next;
        if (slides[i]) slides[i].classList.add('is-active');
        if (dots[i]) {
          dots[i].classList.add('is-active');
          dots[i].setAttribute('aria-selected', 'true');
        }
      };

      const scheduleNext = () => {
        if (timerId !== null) window.clearTimeout(timerId);
        timerId = window.setTimeout(() => {
          const next = (i + 1) % slides.length;
          setActive(next);
          scheduleNext();
        }, dur);
      };

      // Initialize active slide & dot explicitly.
      slides.forEach((el, idx) => {
        if (idx === 0) {
          el.classList.add('is-active');
        } else {
          el.classList.remove('is-active');
        }
      });
      dots.forEach((dot, idx) => {
        if (idx === 0) {
          dot.classList.add('is-active');
          dot.setAttribute('aria-selected', 'true');
        } else {
          dot.classList.remove('is-active');
          dot.setAttribute('aria-selected', 'false');
        }
        dot.addEventListener('click', () => {
          const idxAttr = dot.getAttribute('data-index');
          const idxNum = idxAttr !== null ? parseInt(idxAttr, 10) : idx;
          if (!Number.isFinite(idxNum) || idxNum < 0 || idxNum >= slides.length) return;
          setActive(idxNum);
          scheduleNext();
        });
      });

      scheduleNext();
    });
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
