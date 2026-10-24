<?php
// fpp-multisync-volume settings page (Content Setup -> MultiSync Volume).
global $settings;
$pluginName = 'fpp-multisync-volume';
// The plugin only sends from the master. On a remote (or a bridge) it does
// nothing: the daemon stays quiet there and the page says so.
$fppMode = isset($settings['fppMode']) ? strtolower(trim($settings['fppMode'])) : '';
$isPlayer = ($fppMode === '' || $fppMode === 'player' || $fppMode === 'master');
?>
<div id="global" class="settings">
  <h2>MultiSync Volume</h2>
  <p class="text-body-secondary">
    Mirrors this FPP master's volume to its MultiSync remotes, so the volume
    slider on the status page (and "Volume Set" in playlists or presets)
    controls the audio that actually plays on the remotes. The master's own
    volume is never changed by this plugin.
  </p>

<?php if (!$isPlayer) { ?>
  <div class="alert alert-warning">
    <b>This FPP is in <?php echo htmlspecialchars($fppMode); ?> mode.</b>
    MultiSync Volume only works on the master, so nothing is sent from this
    box whatever you set below. Install and enable it on the FPP that runs the
    show (player mode) instead.
  </div>
<?php } ?>

  <div class="container-fluid px-0">
    <div class="row mb-3 align-items-center">
      <div class="col-sm-4 col-lg-3 fw-semibold">Enable</div>
      <div class="col-sm-8 col-lg-9">
        <?php PrintSettingCheckbox('Enable', 'Enabled', 0, 0, '1', '0', $pluginName); ?>
        <span class="text-body-secondary ms-2">Nothing is sent to the remotes while this is off.</span>
      </div>
    </div>
    <div class="row mb-3 align-items-center">
      <div class="col-sm-4 col-lg-3 fw-semibold">Send to</div>
      <div class="col-sm-8 col-lg-9">
        <?php PrintSettingSelect('Send to', 'TargetMode', 0, 0, 'all',
            array('All remotes' => 'all', 'Selected remotes only' => 'selected'), $pluginName); ?>
      </div>
    </div>
    <div class="row mb-3 align-items-center">
      <div class="col-sm-4 col-lg-3 fw-semibold">Re-send interval</div>
      <div class="col-sm-8 col-lg-9">
        <?php PrintSettingSelect('Re-send interval', 'ResendSeconds', 0, 0, '60',
            array('Off' => '0', 'Every 10 seconds' => '10', 'Every 30 seconds' => '30',
                  'Every minute' => '60', 'Every 5 minutes' => '300'), $pluginName); ?>
        <div class="form-text">
          Volume changes are always sent right away. On top of that, the current
          volume is sent again at this interval even when nothing changed, so a
          remote that restarted (and came back at its default volume) is corrected.
        </div>
      </div>
    </div>
    <div class="form-text mb-2">
      Enable, Send to and Re-send interval are saved as soon as you change them.
    </div>
  </div>

  <h3 class="mt-4">Remotes</h3>
  <p class="text-body-secondary">
    Remotes found by MultiSync (this box itself is never a target). The offset
    is added to the master's volume for that remote (for example -10 for a
    remote with louder speakers) and is used in both modes. <span id="msvSendNote"></span>
  </p>
  <div class="table-responsive">
    <table class="table table-sm align-middle" id="msvRemotes">
      <thead>
        <tr><th>Send</th><th>Remote</th><th>Address</th><th style="width: 8rem;">Offset</th></tr>
      </thead>
      <tbody><tr><td colspan="4" class="text-body-secondary">Looking for remotes&hellip;</td></tr></tbody>
    </table>
  </div>
  <button type="button" class="buttons btn-success" id="msvSave">Save remotes</button>
  <span class="text-body-secondary ms-2">Offsets and the selection only take effect once you press this.</span>
  <div class="text-body-secondary mt-2" id="msvMaster"></div>
</div>

<script>
(function () {
  var plugin = '<?php echo $pluginName; ?>';
  var settingsUrl = 'api/plugin/' + plugin + '/settings/Remotes';
  var masterTimer = null;
  var targetMode = 'all';

  function parseConfig(text) {
    var map = {};
    (text || '').split(',').forEach(function (part) {
      part = part.trim();
      if (!part) return;
      var bits = part.split('=');
      map[bits[0].trim()] = parseInt(bits[1] || '0', 10) || 0;
    });
    return map;
  }

  function selectedMode() {
    // The select's own value while the page is open; the saved value is the
    // fallback in case FPP renders the control under another id.
    var live = $('#TargetMode').val();
    return (live || targetMode) === 'selected';
  }

  // The "Send" column only means something with "Selected remotes only".
  function applyMode() {
    var selMode = selectedMode();
    $('#msvRemotes .msvSend').prop('disabled', !selMode);
    $('#msvSendNote').text(selMode
      ? '"Send" picks which remotes get the volume.'
      : '"Send" is only used with "Selected remotes only", so it is off here.');
  }

  function row(ip, name, offsets, selected) {
    var tr = $('<tr>').attr('data-ip', ip);
    var cb = $('<input type="checkbox" class="form-check-input msvSend">').prop('checked', selected.indexOf(ip) >= 0);
    var off = $('<input type="number" min="-100" max="100" step="1" class="form-control form-control-sm msvOffset">')
      .val(offsets[ip] || 0);
    tr.append($('<td>').append(cb), $('<td>').text(name || ip), $('<td>').text(ip), $('<td>').append(off));
    return tr;
  }

  function showMaster() {
    $.get('api/fppd/volume?simple=true', function (v) {
      $('#msvMaster').text('Master volume: ' + v);
    });
  }

  function load() {
    $.when($.getJSON(settingsUrl), $.getJSON('api/plugin/' + plugin + '/settings/Selected'),
           $.getJSON('api/fppd/multiSyncSystems'),
           $.getJSON('api/plugin/' + plugin + '/settings/TargetMode')).done(function (s, sel, m, tm) {
      targetMode = (tm[0].TargetMode || 'all');
      var offsets = parseConfig(s[0].Remotes);
      var selected = (sel[0].Selected || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      var body = $('#msvRemotes tbody').empty();
      var seen = {}, local = {};
      (m[0].systems || []).forEach(function (sys) {
        if (sys.local) { local[sys.address || ''] = true; }
      });
      (m[0].systems || []).forEach(function (sys) {
        var ip = sys.address || '';
        if (sys.fppModeString !== 'remote' || !ip || ip.indexOf(':') >= 0 || seen[ip] || local[ip]) return;
        seen[ip] = true;
        body.append(row(ip, sys.hostname, offsets, selected));
      });
      Object.keys(offsets).concat(selected).forEach(function (ip) {   // configured but not seen right now
        if (!seen[ip] && !local[ip]) { seen[ip] = true; body.append(row(ip, '(not seen right now)', offsets, selected)); }
      });
      if (!body.children().length) {
        body.append('<tr><td colspan="4" class="text-body-secondary">No remotes found. Put the remotes in Remote mode and check MultiSync.</td></tr>');
      }
      applyMode();
    }).fail(function () {
      $.jGrowl('Could not load remotes', { themeState: 'danger' });
    });
    showMaster();
  }

  function put(key, value) {
    return $.ajax({ url: 'api/plugin/' + plugin + '/settings/' + key, type: 'PUT', data: value, contentType: 'text/plain' });
  }

  $('#msvSave').on('click', function () {
    var offsets = [], selected = [];
    $('#msvRemotes tbody tr[data-ip]').each(function () {
      var tr = $(this), ip = tr.attr('data-ip');
      var off = Math.max(-100, Math.min(100, parseInt(tr.find('.msvOffset').val(), 10) || 0));
      if (off !== 0) offsets.push(ip + '=' + off);
      if (tr.find('.msvSend').is(':checked')) selected.push(ip);
    });
    $.when(put('Remotes', offsets.join(',')), put('Selected', selected.join(',')))
      .done(function () { $.jGrowl('Remotes saved', { themeState: 'success' }); load(); })
      .fail(function () { $.jGrowl('Saving failed', { themeState: 'danger' }); });
  });

  $(document).on('change', '#TargetMode', function () {
    targetMode = $(this).val();
    applyMode();
  });

  load();
  masterTimer = setInterval(showMaster, 5000);
  $(window).on('unload', function () { clearInterval(masterTimer); });
})();
</script>
