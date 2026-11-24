/* Script for resources/views/auth/customer-login.blade.php: the card's notice, once more as the site's alert. */
      if (document.getElementById('accountDisabledNotice').dataset.revoked) venusepAlert('This account was closed and can’t be used again.', { title: 'Account closed' });
      else venusepAlert('This account has been disabled, so it can’t sign in. If you think this is a mistake, contact the venue office.', { title: 'Account disabled' });
    
