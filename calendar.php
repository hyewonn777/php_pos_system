<?php require 'auth.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Calendar</title>
  <link rel="stylesheet" href="admin.css"/>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    .cal-toolbar{display:flex; gap:8px; align-items:center; margin-bottom:12px}
    .calendar{display:grid; grid-template-columns:repeat(7,1fr); gap:8px}
    .cal-cell{
      background:var(--card); border:1px solid var(--border); border-radius:10px; padding:8px; min-height:90px; position:relative;
    }
    .cal-cell .d{font-weight:800; opacity:.7}
    .badge{display:inline-block; padding:2px 6px; border-radius:999px; font-size:12px; margin-top:6px}
    .vacant{background:#dcfce7; color:#065f46}
    .occupied{background:#fee2e2; color:#7f1d1d}
    .pending{background:#fef9c3; color:#854d0e}
    .legend{display:flex; gap:10px; margin:10px 0}
    .legend span{display:inline-flex; align-items:center; gap:6px}
    .legend .dot{width:10px; height:10px; border-radius:999px; display:inline-block}
    .dot.v{background:#22c55e}.dot.o{background:#ef4444}.dot.p{background:#eab308}
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="content">
    <h1>Calendar (Photography / Videography)</h1>

    <div class="cal-toolbar">
      <button id="prevM" class="btn-ghost">Prev</button>
      <div id="ym" style="font-weight:800"></div>
      <button id="nextM" class="btn-ghost">Next</button>
      <select id="mediaSel">
        <option>Photography</option>
        <option>Videography</option>
      </select>
      <div class="legend">
        <span><i class="dot v"></i> Vacant</span>
        <span><i class="dot p"></i> Pending</span>
        <span><i class="dot o"></i> Occupied</span>
      </div>
    </div>

    <div class="cards">
      <div class="card" style="flex:2">
        <div class="calendar" id="calGrid"></div>
      </div>
      <div class="card" style="flex:1">
        <h3>New Booking</h3>
        <form id="bookingForm" class="inline-form">
          <input type="text" name="client_name" placeholder="Client name" required/>
          <select name="media_type">
            <option>Photography</option>
            <option>Videography</option>
          </select>
          <input type="date" name="event_date" required/>
          <select name="status">
            <option>Pending</option>
            <option>Confirmed</option>
            <option>Completed</option>
            <option>Cancelled</option>
          </select>
          <input type="text" name="notes" placeholder="Notes (optional)"/>
          <button type="submit">Save Booking</button>
        </form>
        <div id="bAlert"></div>
      </div>
    </div>
  </div>

<script>
let cur = new Date(); // current visible month
function ymKey(d){return d.getFullYear()+'-'+(d.getMonth()+1);}
function renderCalendar(){
  const y = cur.getFullYear(), m = cur.getMonth(); // 0-11
  $("#ym").text(cur.toLocaleString('default',{month:'long'})+' '+y);
  const first = new Date(y,m,1);
  const startDow = first.getDay(); // 0 Sun
  const daysInMonth = new Date(y, m+1, 0).getDate();

  const grid = $("#calGrid").empty();
  // pad with previous month blanks
  for(let i=0;i<startDow;i++) grid.append(`<div class="cal-cell"></div>`);

  // fetch bookings for month/type
  const media = $("#mediaSel").val();
  $.getJSON('api_bookings.php',{year:y, month:m+1, media:media}, data=>{
    const byDate = {};
    data.forEach(b=>{
      byDate[b.event_date] = b; // one per day by table unique constraint
    });

    for(let d=1; d<=daysInMonth; d++){
      const dateStr = `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const b = byDate[dateStr];
      let badge = `<span class="badge vacant">Vacant</span>`;
      if (b){
        if (b.status==='Confirmed') badge = `<span class="badge occupied">Occupied</span>`;
        else if (b.status==='Pending') badge = `<span class="badge pending">Pending</span>`;
        else if (b.status==='Completed') badge = `<span class="badge occupied">Completed</span>`;
        else if (b.status==='Cancelled') badge = `<span class="badge vacant">Cancelled</span>`;
      }
      grid.append(`
        <div class="cal-cell">
          <div class="d">${d}</div>
          ${badge}
          ${b ? `<div style="margin-top:6px;font-size:12px;opacity:.8">${b.client_name}</div>`:''}
        </div>`);
    }
  });
}

$("#prevM").on('click', ()=>{ cur.setMonth(cur.getMonth()-1); renderCalendar(); });
$("#nextM").on('click', ()=>{ cur.setMonth(cur.getMonth()+1); renderCalendar(); });
$("#mediaSel").on('change', renderCalendar);

$("#bookingForm").on('submit', function(e){
  e.preventDefault();
  const fd = new FormData(this);
  $.ajax({url:'api_bookings.php', method:'POST', data:fd, contentType:false, processData:false})
   .done(j=>{
     $("#bAlert").html(`<div class="alert ${j.ok?'alert-success':'alert-error'}">${j.ok?'Saved':'Error: '+j.error}</div>`);
     renderCalendar();
     this.reset();
   });
});

$(function(){ renderCalendar(); });
</script>
</body>
</html>
