<?php
// fpp-multisync-volume settings page (Content Setup -> MultiSync Volume).
$pluginName = 'fpp-multisync-volume';
?>
<div id="global" class="settings">
  <h2>MultiSync Volume</h2>
  <p class="text-body-secondary">
    Mirrors this FPP master's volume to its MultiSync remotes, so the volume
    slider on the status page (and "Volume Set" in playlists or presets)
    controls the audio that actually plays on the remotes. The master's own
    volume is never changed by this plugin.
  </p>

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
  </div>

  <h3 class="mt-4">Remotes</h3>
  <p class="text-body-secondary">
    Remotes found by MultiSync. The offset is added to the master's volume for
    that remote (for example -10 for a remote with louder speakers) and is used
    in both modes. "Send" only matters with "Selected remotes only".
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
  <span class="text-body-secondary ms-2" id="msvMaster"></span>
</div>

<script>
(function () {
  var plugin = '<?php echo $pluginName; ?>';
  var settingsUrl = 'api/plugin/' + plugin + '/settings/Remotes';

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

  function row(ip, name, offsets, selected) {
    var tr = $('<tr>').attr('data-ip', ip);
    var cb = $('<input type="checkbox" class="form-check-input msvSend">').prop('checked', selected.indexOf(ip) >= 0);
    var off = $('<input type="number" min="-100" max="100" step="1" class="form-control form-control-sm msvOffset">')
      .val(offsets[ip] || 0);
    tr.append($('<td>').append(cb), $('<td>').text(name || ip), $('<td>').text(ip), $('<td>').append(off));
    return tr;
  }

  function load() {
    $.when($.getJSON(settingsUrl), $.getJSON('api/plugin/' + plugin + '/settings/Selected'),
           $.getJSON('api/fppd/multiSyncSystems')).done(function (s, sel, m) {
      var offsets = parseConfig(s[0].Remotes);
      var selected = (sel[0].Selected || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      var body = $('#msvRemotes tbody').empty();
      var seen = {};
      (m[0].systems || []).forEach(function (sys) {
        var ip = sys.address || '';
        if (sys.fppModeString !== 'remote' || !ip || ip.indexOf(':') >= 0 || seen[ip]) return;
        seen[ip] = true;
        body.append(row(ip, sys.hostname, offsets, selected));
      });
      Object.keys(offsets).concat(selected).forEach(function (ip) {   // configured but not seen right now
        if (!seen[ip]) { seen[ip] = true; body.append(row(ip, '(not seen right now)', offsets, selected)); }
      });
      if (!body.children().length) {
        body.append('<tr><td colspan="4" class="text-body-secondary">No remotes found. Put the remotes in Remote mode and check MultiSync.</td></tr>');
      }
    }).fail(function () {
      $.jGrowl('Could not load remotes', { themeState: 'danger' });
    });
    $.get('api/fppd/volume?simple=true', function (v) { $('#msvMaster').text('Master volume: ' + v); });
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

  load();
})();
</script>
