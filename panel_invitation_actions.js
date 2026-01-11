document.addEventListener('DOMContentLoaded', () => {
  const respondButtons = document.querySelectorAll('.respondBtn');
  respondButtons.forEach((btn) => {
    btn.addEventListener('click', async () => {
      const panelId = btn.dataset.id;
      const response = btn.dataset.response;
      if (!panelId || !response) {
        return;
      }
      if (!confirm(`Are you sure you want to ${response.toLowerCase()} this invitation?`)) {
        return;
      }

      const formData = new FormData();
      formData.append('panel_id', panelId);
      formData.append('response', response);

      try {
        const res = await fetch('update_response.php', { method: 'POST', body: formData });
        const text = (await res.text()).trim();
        if (text === 'success') {
          alert(`You have ${response.toLowerCase()} the invitation.`);
          window.location.reload();
        } else {
          alert('Failed to update response.');
        }
      } catch (error) {
        alert('An error occurred while updating your response.');
      }
    });
  });

  const scheduleSearch = document.getElementById('scheduleSearch');
  if (scheduleSearch) {
    scheduleSearch.addEventListener('input', () => {
      const value = scheduleSearch.value.toLowerCase();
      document.querySelectorAll('#schedule .schedule-card').forEach((card) => {
        const searchable = (card.dataset.search || '').toLowerCase();
        card.style.display = searchable.includes(value) ? '' : 'none';
      });
    });
  }

  if (window.location.hash) {
    const target = document.querySelector(window.location.hash);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }
});
