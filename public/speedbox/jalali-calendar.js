// Shared by the infrastructure ranking and invoice date controls.
window.SpeedboxJalaliCalendar = (() => {
  function todayDate(date = new Date()) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date).map(part=>[part.type,part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
  }
  // Persian calendar is used for every displayed date. ISO dates stay internal to the API.
  const persianDateFormat = new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'});
  const persianPartsFormat = new Intl.DateTimeFormat('en-US-u-ca-persian',{timeZone:'UTC',year:'numeric',month:'numeric',day:'numeric'});
  const persianMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const faNumber = value => Number(value).toLocaleString('fa-IR',{useGrouping:false});
  const displayDate = iso => iso ? persianDateFormat.format(new Date(iso+'T12:00:00Z')) : '—';
  function persianParts(date) {
    const parts=Object.fromEntries(persianPartsFormat.formatToParts(date).map(part=>[part.type,part.value]));
    return {year:Number(parts.year),month:Number(parts.month),day:Number(parts.day)};
  }
  function persianMonthStart(year,month) {
    const target=year*10000+month*100+1;
    let low=Math.floor(Date.UTC(year+621,0,1)/86400000),high=Math.floor(Date.UTC(year+622,11,31)/86400000);
    while(low<high){
      const mid=Math.floor((low+high)/2),p=persianParts(new Date(mid*86400000));
      if(p.year*10000+p.month*100+p.day<target)low=mid+1;else high=mid;
    }
    return low;
  }
  function monthDays(year, month) {
    const start = persianMonthStart(year, month);
    const end = persianMonthStart(month === 12 ? year + 1 : year, month === 12 ? 1 : month + 1);
    const offset = (new Date(start * 86400000).getUTCDay() + 1) % 7;
    return [
      ...Array(offset).fill(null),
      ...Array.from({ length: end - start }, (_, index) => ({
        number: index + 1,
        iso: new Date((start + index) * 86400000).toISOString().slice(0, 10),
      })),
    ];
  }

  return { todayDate, displayDate, persianParts, persianMonths, faNumber, monthDays };
})();
