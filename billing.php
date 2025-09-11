<?php require 'auth.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Billing</title>
  <link rel="stylesheet" href="admin.css"/>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="content">
    <h1>Billing</h1>
    <div class="billing-layout">
      <!-- Section 1: Categories -->
      <div class="billing-col">
        <h3>Categories</h3>
        <div class="category-list" id="categoryList"></div>
      </div>

      <!-- Section 2: Items -->
      <div class="billing-col">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <h3>Items</h3>
          <button class="btn btn-ghost" id="showAll">Show All</button>
        </div>
        <div class="items-grid" id="itemsGrid"></div>
      </div>

      <!-- Section 3: Bill -->
      <div class="billing-col">
        <h3>Bill</h3>
        <table class="table" id="billTable">
          <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Subtotal</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="bill-actions">
          <div style="margin-left:auto; font-weight:800">Total: ₱<span id="total">0.00</span></div>
        </div>
        <div class="bill-actions">
          <button id="saveBill">Save Bill</button>
          <button class="btn-ghost" id="clearBill">Clear</button>
        </div>
      </div>
    </div>
  </div>

<script>
let bill = []; // {id,name,price,qty}

function renderBill(){
  const tbody = $("#billTable tbody").empty();
  let total=0;
  bill.forEach((it,idx)=>{
    const sub = it.price*it.qty;
    total += sub;
    const tr = $(`
      <tr>
        <td>${it.name}</td>
        <td>
          <input type="number" min="1" value="${it.qty}" style="width:70px"/>
        </td>
        <td>₱${it.price.toFixed(2)}</td>
        <td>₱${sub.toFixed(2)}</td>
        <td><button class="btn-ghost">Remove</button></td>
      </tr>`);
    tr.find('input').on('input', function(){
      const v = Math.max(1, parseInt(this.value||'1',10));
      bill[idx].qty = v;
      renderBill();
    });
    tr.find('button').on('click', function(){
      bill.splice(idx,1);
      renderBill();
    });
    tbody.append(tr);
  });
  $("#total").text(total.toFixed(2));
}

function addToBill(item){
  const found = bill.find(b=>b.id===item.id);
  if(found){ found.qty++; } else { bill.push({...item, qty:1}); }
  renderBill();
}

function loadCategories(){
  $.getJSON('api_categories.php', data=>{
    const list = $("#categoryList").empty();
    const allBtn = $('<button class="active">All</button>').on('click',()=>{
      $("#categoryList button").removeClass('active'); allBtn.addClass('active'); loadItems('ALL');
    });
    list.append(allBtn);
    data.forEach(cat=>{
      const b = $(`<button>${cat}</button>`).on('click',()=>{
        $("#categoryList button").removeClass('active'); b.addClass('active'); loadItems(cat);
      });
      list.append(b);
    });
  });
}

function loadItems(category){
  const url = 'api_items.php?category='+encodeURIComponent(category||'ALL');
  $.getJSON(url, items=>{
    const grid = $("#itemsGrid").empty();
    items.forEach(it=>{
      const card = $(`
        <div class="item-card">
          <img src="${it.image_path}" alt="">
          <div class="meta">
            <div class="name">${it.name}</div>
            <div class="price">₱${parseFloat(it.price).toFixed(2)}</div>
          </div>
        </div>`);
      card.on('click', ()=> addToBill({id:it.id, name:it.name, price:parseFloat(it.price)}));
      grid.append(card);
    });
  });
}

$("#showAll").on('click',()=>{ $("#categoryList button").removeClass('active'); $("#categoryList button:first").addClass('active'); loadItems('ALL'); });
$("#clearBill").on('click',()=>{ bill=[]; renderBill(); });
$("#saveBill").on('click', ()=>{
  if (bill.length===0){ alert('Bill is empty'); return; }
  fetch('api_save_bill.php',{method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({items:bill})})
    .then(r=>r.json()).then(j=>{
      if(j.ok){ alert('Bill saved. ID: '+j.bill_id+' | Total: ₱'+j.total.toFixed(2)); bill=[]; renderBill(); }
      else alert('Error saving bill.');
    });
});

$(function(){
  loadCategories();
  loadItems('ALL'); // initial: show all items across categories
});
</script>
</body>
</html>
