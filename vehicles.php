<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

render_header('vehicles', 'page.vehicles', 'Registered vehicles');
?>
<main class="wrap">
  <div class="page-head">
    <h1 data-i18n="veh.title">Registered vehicles</h1>
    <p class="muted" data-i18n="veh.subtitle">Search by plate, owner, make or model. Filter by body type or registration date.</p>
  </div>

  <section class="card">
    <form id="search-form" class="filters">
      <div class="field">
        <label for="q" data-i18n="veh.search">Search</label>
        <input type="text" id="q" name="q" data-i18n-placeholder="veh.search.ph" placeholder="Plate, owner, make or model" maxlength="100">
      </div>
      <div class="field">
        <label for="type" data-i18n="veh.type">Body type</label>
        <select id="type" name="type">
          <option value="" data-i18n="veh.type.all">All types</option>
<?php foreach (BODY_TYPES as $type): ?>
          <option value="<?= e($type) ?>" data-i18n="type.<?= e($type) ?>"><?= e($type) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="date_from" data-i18n="veh.from">Registered from</label>
        <input type="date" id="date_from" name="date_from">
      </div>
      <div class="field">
        <label for="date_to" data-i18n="veh.to">Registered to</label>
        <input type="date" id="date_to" name="date_to">
      </div>
      <div class="field">
        <button type="button" class="btn ghost" id="btn-reset-filters" data-i18n="veh.reset">Reset</button>
      </div>
    </form>

    <div class="table-meta">
      <span id="veh-count" role="status"></span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th data-i18n="th.plate">Plate</th>
            <th data-i18n="th.owner">Owner</th>
            <th data-i18n="th.phone">Phone</th>
            <th data-i18n="th.vehicle">Vehicle</th>
            <th data-i18n="th.color">Colour</th>
            <th data-i18n="th.type">Type</th>
            <th data-i18n="th.fee.usd">Fee (USD)</th>
            <th data-i18n="th.fee.paid">Fee paid</th>
            <th data-i18n="th.date">Registered</th>
          </tr>
        </thead>
        <tbody id="veh-body"></tbody>
      </table>
      <p class="empty" id="veh-empty" data-i18n="veh.empty" hidden>No vehicles match your search. Try a shorter search or clear the filters.</p>
    </div>

    <nav class="pager" id="pager" aria-label="Pagination"></nav>
  </section>
</main>
<?php render_footer();
