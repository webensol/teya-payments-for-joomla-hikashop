document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.teya-refund-panel').forEach(panel => {
    const messages = JSON.parse(panel.dataset.messages || '{}');
    const text = key => messages[key] || key;
    panel.querySelectorAll('button[data-action]').forEach(button => button.addEventListener('click', async () => {
      if (button.disabled) return;
      if (!window.confirm(text(button.dataset.action === 'refund' ? 'REFUND_CONFIRM' : 'REFUND_CONFIRM_RETRY'))) return;
      button.disabled = true;
      const output = panel.querySelector('.teya-refund-result');
      const body = new URLSearchParams({teya_action:button.dataset.action, order_id:panel.dataset.order, request_key:panel.dataset.key});
      body.set(panel.dataset.token,'1');
      const amount = panel.querySelector('.teya-refund-amount');
      if (amount) body.set('amount',amount.value.trim());
      try {
        const response = await fetch(panel.dataset.url,{method:'POST',credentials:'same-origin',body});
        const result = await response.json();
        if (!response.ok || result.error) throw new Error();
        output.textContent = result.pending ? (result.message || text('REFUND_PENDING')) : text('REFUND_CONFIRMED');
        window.setTimeout(() => window.location.reload(),1500);
      } catch (error) { output.textContent = text('REFUND_ERROR') + ' ' + text('REFUND_REFRESH'); }
    }));
  });
});
