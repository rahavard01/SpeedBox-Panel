{{-- Content rendered as a child of the original Vue Admin layout. --}}
<template id="speedbox-infrastructure-template">
  <div class="billing-root infrastructure-heading">
    <h3 class="topup-title" id="pageTitle">داشبورد</h3>
  </div>
  <section id="content" class="content" aria-live="polite"></section>
  <div class="speedbox-infrastructure-overlay" id="infrastructureOverlay">
    <div class="modal-backdrop" id="modalBackdrop" hidden>
      <div class="modal-outer">
        <section class="create-modal infrastructure-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
          <div class="announcement-modal-heading"><h3 class="topup-title" id="modalTitle"></h3></div>
          <div id="modalBody" class="modal-body"></div>
        </section>
      </div>
    </div>
  </div>
</template>
