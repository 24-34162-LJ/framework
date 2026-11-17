/*
 * FILE PURPOSE
 * File   : public/assets/js/transactions.js
 * Feature: Transactions
 * Purpose: Transactions form: shows only the bills of the chosen shipment.
 */

(() => {
  const shipment = document.getElementById('txnShipment');
  const bill = document.getElementById('txnBill');
  if (!shipment || !bill) return;
  const filterBills = () => {
    const selectedShipment = shipment.value;
    [...bill.options].forEach((option, index) => {
      if (index === 0) return;
      const matches = !selectedShipment || option.dataset.shipment === selectedShipment;
      option.hidden = !matches;
      if (!matches && option.selected) bill.value = '';
    });
  };
  shipment.addEventListener('change', filterBills);
  filterBills();
})();
