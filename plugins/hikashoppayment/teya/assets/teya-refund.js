document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.teya-refund-panel').forEach(panel => {
    panel.querySelectorAll('button[data-action]').forEach(button => button.addEventListener('click', async () => {
      if (button.disabled) return;
      if (!window.confirm(button.dataset.action === 'refund' ? 'Send this refund to Teya?' : 'Retry the existing refund with Teya? This may return money to the customer. If authorization was refused, resolve permissions first.')) return;
      button.disabled = true;
      const output = panel.querySelector('.teya-refund-result');
      const body = new URLSearchParams({teya_action:button.dataset.action, order_id:panel.dataset.order, request_key:panel.dataset.key});
      body.set(panel.dataset.token,'1');
      const amount = panel.querySelector('.teya-refund-amount');
      if (amount) body.set('amount',amount.value.trim());
      try {
        const response = await fetch(panel.dataset.url,{method:'POST',credentials:'same-origin',body});
        const result = await response.json();
        if (!response.ok || result.error) throw new Error('Refund could not be processed. Reload the order to review its saved state.');
        output.textContent = result.pending ? (result.message || 'Refund outcome is not confirmed.') : 'Refund confirmed.';
        window.setTimeout(() => window.location.reload(),1500);
      } catch (error) { output.textContent = error.message + ' Refresh before retrying.'; }
    }));
  });
});
