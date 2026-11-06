/* Script for resources/views/partials/customer-tabs.blade.php. */
  /* iPhones report the safe area only to a page that asks for it; phones only, so a wide
     screen's sidebar stays clear of the notch */
  if (matchMedia('(max-width:767.98px)').matches) document.querySelector('meta[name=viewport]').content += ', viewport-fit=cover';
