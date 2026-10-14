/* Shared treatment-duration display. Values remain integer minutes. */
window.ServiceDuration = {
  format(value) {
    const minutes = Number(value);
    if (!Number.isInteger(minutes) || minutes <= 0) return 'Not set';
    const hours = Math.floor(minutes / 60), remainder = minutes % 60;
    return [hours ? `${hours} hour${hours === 1 ? '' : 's'}` : '', remainder ? `${remainder} minute${remainder === 1 ? '' : 's'}` : ''].filter(Boolean).join(' ');
  },
  total(services) {
    return (services || []).reduce((sum, service) => sum + (Number(service.durationMinutes) || 30), 0);
  },
  endTime(date, time, minutes) {
    if (!date || !time || !minutes) return '';
    const match = String(time).trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
    if (!match) return '';
    let hour = Number(match[1]);
    if (match[3]) hour = hour % 12 + (match[3].toUpperCase() === 'PM' ? 12 : 0);
    const start = new Date(`${date}T${String(hour).padStart(2, '0')}:${match[2]}:00+08:00`);
    const end = new Date(start.getTime() + Number(minutes) * 60000);
    return Number.isFinite(end.getTime()) ? end.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila'}) : '';
  }
};
