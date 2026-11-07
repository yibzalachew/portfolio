let price = 1.87;
let cid = [
  ['PENNY', 1.01],
  ['NICKEL', 2.05],
  ['DIME', 3.1],
  ['QUARTER', 4.25],
  ['ONE', 90],
  ['FIVE', 55],
  ['TEN', 20],
  ['TWENTY', 60],
  ['ONE HUNDRED', 100]
];

const currencyUnits = [
  ['ONE HUNDRED', 100],
  ['TWENTY', 20],
  ['TEN', 10],
  ['FIVE', 5],
  ['ONE', 1],
  ['QUARTER', 0.25],
  ['DIME', 0.1],
  ['NICKEL', 0.05],
  ['PENNY', 0.01]
];

document.getElementById("purchase-btn").addEventListener("click", function() {
  let cashInput = parseFloat(document.getElementById("cash").value);
  let changeDue = cashInput - price;
  let change = [];

  if (cashInput < price) {
    alert("Customer does not have enough money to purchase the item");
    return;
  }

  if (cashInput === price) {
    document.getElementById("change-due").textContent = "No change due - customer paid with exact cash";
    return;
  }

  if (getCashInDrawerTotal(cid) < changeDue) {
    document.getElementById("change-due").textContent = "Status: INSUFFICIENT_FUNDS";
    return;
  }

  if (getCashInDrawerTotal(cid) === changeDue) {
    let changeStr = "Status: CLOSED ";
    for (let i = 0; i < currencyUnits.length; i++) {
      let [unit, value] = currencyUnits[i];
      let unitAmount = cid.find(([name]) => name === unit)[1];
      if (unitAmount > 0) {
        changeStr += `${unit}: $${unitAmount.toFixed(2)} `;
      }
    }
    document.getElementById("change-due").textContent = changeStr.trim();
    return;
  }

  for (let i = 0; i < currencyUnits.length; i++) {
    let [unit, value] = currencyUnits[i];
    let unitAmount = cid.find(([name]) => name === unit)[1];

    if (changeDue >= value && unitAmount > 0) {
      let changeForUnit = 0;
      while (changeDue >= value && unitAmount > 0) {
        changeForUnit += value;
        changeDue -= value;
        unitAmount -= value;
        changeDue = Math.round(changeDue * 100) / 100;
      }
      if (changeForUnit > 0) {
        change.push([unit, changeForUnit]);
      }
    }
  }

  if (changeDue > 0) {
    document.getElementById("change-due").textContent = "Status: INSUFFICIENT_FUNDS";
  } else {
    let changeStr = "Status: OPEN ";
    change.forEach(([unit, amount]) => {
      changeStr += `${unit}: $${amount.toFixed(2)} `;
    });

    document.getElementById("change-due").textContent = changeStr.trim();
  }
});

function getCashInDrawerTotal(cid) {
  return cid.reduce((total, [unit, amount]) => total + amount, 0);
}
