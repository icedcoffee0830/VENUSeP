/* Script for legacy/includes/pricing.php. */
  /* Is this address a USeP account? The domain must be EXACTLY usep.edu.ph
     or a subdomain of it. A plain "endsWith('.usep.edu.ph')" is wrong twice
     over: it rejects jmdelacruz@usep.edu.ph (no leading dot) and it would
     accept nothing useful, while "endsWith('usep.edu.ph')" would wave through
     notusep.edu.ph. Evidence for staff only — never a grant. */
  function isUsepAccount(email) {
    const at = String(email || '').toLowerCase().split('@');
    if (at.length !== 2) return false;
    const d = at[1];
    return d === pricingPage.USEP_MAIL_DOMAIN || d.endsWith(('.' + pricingPage.USEP_MAIL_DOMAIN));
  }

  /* Pricing for one booking. `affiliated` is the customer's CLAIM; staff
     confirm it later, which is why the UI calls the discount provisional
     until then. The discounted total is usep_discounted()'s rule, as approval stores it
     (sp_approve_booking, migration 19): up to the whole peso, down when up gives back
     the full price, never 0 (then the full price and no discount). The discount is the rest. */
  function priceWithDiscount(roomPrice, affiliated) {
    let pct = affiliated === true ? DISCOUNT_PERCENT : 0, total = roomPrice;
    if (pct > 0) {
      const exact = Math.round((roomPrice - Math.round(roomPrice * pct) / 100) * 100) / 100;
      total = Math.ceil(exact) < roomPrice ? Math.ceil(exact) : Math.floor(exact);
      if (total <= 0) { total = roomPrice; pct = 0; }
    }
    return { roomPrice: roomPrice, discountPercent: pct, discountAmount: roomPrice - total, total: total };
  }
