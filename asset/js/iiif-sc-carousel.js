(() => {
  const init = () => {
    const sections = document.querySelectorAll('.iiif-sc');
    sections.forEach(section => {
      const slides = Array.from(section.querySelectorAll('.iiif-sc__slide'));
      if (slides.length <= 1) return;
      let i = 0;
      const dur = (window.IIIF_SC_DURATION || 6000);
      const tick = () => {
        slides[i].classList.remove('is-active');
        i = (i + 1) % slides.length;
        slides[i].classList.add('is-active');
        setTimeout(tick, dur);
      };
      setTimeout(tick, dur);
    });
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
