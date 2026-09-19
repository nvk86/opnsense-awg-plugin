<script>
    $(document).ready(function () {

        // ── I1-I5 CPS fields contain angle brackets that get HTML-encoded
        // by the framework. Decode entities in the dialog fields after load.
        function decodeIFields() {
            var el = document.createElement('textarea');
            ['i1','i2','i3','i4','i5'].forEach(function (f) {
                var $input = $('[id="instance.' + f + '"]');
                var v = $input.val();
                if (v && (v.indexOf('&') !== -1)) {
                    // Decode repeatedly until stable (handles multi-level encoding)
                    var prev = v;
                    while (true) {
                        el.innerHTML = prev;
                        var decoded = el.value;
                        if (decoded === prev) break;
                        prev = decoded;
                    }
                    $input.val(prev);
                }
            });
        }

        // ── Load general form ─────────────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/amneziawg/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // Remove stale command tooltips after closing AWG edit dialogs.
        $('#{{formGridInstance['edit_dialog_id']}}, #{{formGridServer['edit_dialog_id']}}, #{{formGridPeer['edit_dialog_id']}}')
            .on('hidden.bs.modal', function () {
                $('.tooltip').remove();
            });

        // ── Tunnels grid ──────────────────────────────────────────────
        // Per-row runtime control: Start/Stop one tunnel without touching
        // the others. A per-row Stop sets a per-instance flag so the
        // watchdog won't bring the tunnel back (service Start/Restart/Apply
        // resets per-row stops).
        function tunnelRowAction(event, cell, action, legacyThis) {
            // OPNsense UIBootgrid/Tabulator invokes custom commands as
            // method(event, cell). Keep a legacy fallback for older UIBootgrid.
            var uuid = '';
            if (cell && typeof cell.getData === 'function') {
                uuid = (cell.getData() || {}).uuid || '';
            }
            if (!uuid && legacyThis) {
                uuid = $(legacyThis).data('row-id') || $(event && event.currentTarget).data('row-id') || '';
            }
            if (!uuid) {
                alert("{{ lang._('Unable to determine tunnel instance') }}");
                return;
            }

            var $icon = (event && event.currentTarget) ? $(event.currentTarget) : $(legacyThis);
            if (!$icon.hasClass('fa')) {
                $icon = $icon.find('.fa').first();
            }
            var orig = $icon.attr('class') || '';
            if ($icon.hasClass('fa-spinner')) {
                return;
            }
            $icon.attr('class', 'fa fa-spinner fa-spin fa-fw');
            _statusPaused = true;
            $.ajax({
                url:      '/api/amneziawg/service/' + action + '/' + uuid,
                type:     'POST',
                dataType: 'json',
                timeout:  40000,
                success: function (data) {
                    $icon.attr('class', orig);
                    if (!data || data.result !== 'ok') {
                        BootstrapDialog.show({
                            type:    BootstrapDialog.TYPE_DANGER,
                            title:   "{{ lang._('Error') }}",
                            message: (data && data.message) || "{{ lang._('Tunnel action failed') }}",
                            buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                        });
                    }
                    _statusPaused = false;
                    updateStatus();
                    // Refresh the runtime-status column
                    $('#{{formGridInstance['table_id']}}').bootgrid('reload');
                    $('#{{formGridServer['table_id']}}').bootgrid('reload');
                },
                error: function () {
                    $icon.attr('class', orig);
                    _statusPaused = false;
                    alert("{{ lang._('Request failed') }}");
                }
            });
        }

        $("#{{formGridInstance['table_id']}}").UIBootgrid(
            {   'search': '/api/amneziawg/instance/search_item',
                'get':    '/api/amneziawg/instance/get_item/',
                'set':    '/api/amneziawg/instance/set_item/',
                'add':    '/api/amneziawg/instance/add_item/',
                'del':    '/api/amneziawg/instance/del_item/',
                'toggle': '/api/amneziawg/instance/toggle_item/',
                'commands': {
                    start: {
                        filter: function (cell) { var r=cell.getData(); return String(r.enabled)==='1' && (r.runtime==='stopped' || !r.runtime); },
                        method: function (event, cell) { tunnelRowAction(event, cell, 'start_instance', this); },
                        classname: 'fa fa-play fa-fw text-success',
                        title: "{{ lang._('Start') }}",
                        sequence: 1
                    },
                    stop: {
                        filter: function (cell) { var r=cell.getData(); return r.runtime && r.runtime!=='stopped'; },
                        method: function (event, cell) { tunnelRowAction(event, cell, 'stop_instance', this); },
                        classname: 'fa fa-stop fa-fw text-danger',
                        title: "{{ lang._('Stop') }}",
                        sequence: 2
                    },
                    restart: {
                        filter: function (cell) { var r=cell.getData(); return r.runtime && r.runtime!=='stopped'; },
                        method: function (event, cell) { tunnelRowAction(event, cell, 'restart_instance', this); },
                        classname: 'fa fa-refresh fa-fw text-warning',
                        title: "{{ lang._('Restart') }}",
                        sequence: 3
                    }
                },
                'options': {
                    'formatters': {
                        // runtime status from searchItemAction enrichment
                        'tunnelstatus': function (column, row) {
                            switch (row.runtime) {
                                case 'running':
                                    return '<span class="label label-success">{{ lang._('running') }}</span>';
                                case 'no_handshake':
                                    return '<span class="label label-warning">{{ lang._('no handshake') }}</span>';
                                case 'stopped':
                                    return '<span class="label label-danger">{{ lang._('stopped') }}</span>';
                                default:
                                    return '<span class="label label-default">?</span>';
                            }
                        },
                        'healthstatus': function (column, row) {
                            var title = $('<div>').text(row.health_message || '').html();
                            switch (row.health_runtime) {
                                case 'online':
                                    return '<span class="label label-success" title="' + title + '">{{ lang._('online') }}</span>';
                                case 'offline':
                                    var failures = parseInt(row.health_failures || 0, 10);
                                    var suffix = failures > 0 && failures < 3 ? ' ' + failures + '/3' : '';
                                    return '<span class="label label-danger" title="' + title + '">{{ lang._('offline') }}' + suffix + '</span>';
                                case 'waiting':
                                    return '<span class="label label-info" title="' + title + '">{{ lang._('waiting') }}</span>';
                                case 'stale':
                                    return '<span class="label label-warning">{{ lang._('stale') }}</span>';
                                case 'stopped':
                                    return '<span class="label label-default">{{ lang._('stopped') }}</span>';
                                case 'disabled':
                                    return '<span class="label label-default">{{ lang._('disabled') }}</span>';
                                default:
                                    return '<span class="label label-default">?</span>';
                            }
                        }
                    }
                }
            }
        );

        // ── Servers grid ─────────────────────────────────────────────
        $("#{{formGridServer['table_id']}}").UIBootgrid(
            {   'search': '/api/amneziawg/server/search_item',
                'get':    '/api/amneziawg/server/get_item/',
                'set':    '/api/amneziawg/server/set_item/',
                'add':    '/api/amneziawg/server/add_item/',
                'del':    '/api/amneziawg/server/del_item/',
                'toggle': '/api/amneziawg/server/toggle_item/',
                'commands': {
                    start: { filter: function (cell) { var r=cell.getData(); return String(r.enabled)==='1' && (r.runtime==='stopped' || !r.runtime); }, method: function (event, cell) { tunnelRowAction(event, cell, 'start_instance', this); }, classname: 'fa fa-play fa-fw text-success', title: "{{ lang._('Start') }}", sequence: 1 },
                    stop:  { filter: function (cell) { var r=cell.getData(); return r.runtime && r.runtime!=='stopped'; }, method: function (event, cell) { tunnelRowAction(event, cell, 'stop_instance', this); },  classname: 'fa fa-stop fa-fw text-danger', title: "{{ lang._('Stop') }}", sequence: 2 },
                    restart: { filter: function (cell) { var r=cell.getData(); return r.runtime && r.runtime!=='stopped'; }, method: function (event, cell) { tunnelRowAction(event, cell, 'restart_instance', this); }, classname: 'fa fa-refresh fa-fw text-warning', title: "{{ lang._('Restart') }}", sequence: 3 },
                    publickey: {
                        method: function (event, cell) {
                            var row = cell.getData();
                            ajaxGet('/api/amneziawg/server/public_key/' + row.uuid, {}, function (data) {
                                if (data.status === 'ok') {
                                    BootstrapDialog.show({
                                        type: BootstrapDialog.TYPE_INFO,
                                        title: "{{ lang._('Server Public Key') }}",
                                        message: '<code style="word-break:break-all;">' + $('<div>').text(data.public_key).html() + '</code>',
                                        buttons: [{label: "{{ lang._('Close') }}", action: function(d){d.close();}}]
                                    });
                                } else {
                                    alert(data.message || "{{ lang._('Unable to get public key') }}");
                                }
                            });
                        },
                        classname: 'fa fa-key fa-fw',
                        title: "{{ lang._('Show Public Key') }}",
                        sequence: 4
                    }
                },
                'options': {'formatters': {
                    'serverstatus': function (column, row) {
                        switch (row.runtime) {
                            case 'running': return '<span class="label label-success">{{ lang._('peer active') }}</span>';
                            case 'listening': return '<span class="label label-info">{{ lang._('listening') }}</span>';
                            case 'stopped': return '<span class="label label-danger">{{ lang._('stopped') }}</span>';
                            default: return '<span class="label label-default">?</span>';
                        }
                    }
                }}
            }
        );

        // ── Server peers grid ────────────────────────────────────────
        function decodePeerClientConfig(data) {
            if (!data || !data.config_b64) {
                throw new Error("{{ lang._('Missing client configuration payload') }}");
            }
            var binary = atob(data.config_b64);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            if (window.TextDecoder) return new TextDecoder('utf-8').decode(bytes);
            var escaped = '';
            for (var j = 0; j < bytes.length; j++) escaped += '%' + ('0' + bytes[j].toString(16)).slice(-2);
            return decodeURIComponent(escaped);
        }

        function fetchPeerClientConfig(uuid, done) {
            ajaxGet('/api/amneziawg/peer/client_config/' + uuid, {}, function (data) {
                if (!data || data.status !== 'ok') {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_DANGER,
                        title: "{{ lang._('Client Configuration') }}",
                        message: $('<div>').text((data && data.message) || "{{ lang._('Unable to generate client configuration') }}").html(),
                        buttons: [{label: "{{ lang._('Close') }}", action: function(d){d.close();}}]
                    });
                    return;
                }
                done(data);
            });
        }

        function showPeerQr(uuid) {
            fetchPeerClientConfig(uuid, function (data) {
                var $box = $('<div style="text-align:center;"></div>');
                var $qr = $('<div style="display:inline-block;background:#fff;padding:12px;"></div>');
                var $progress = $('<div style="margin-top:8px;color:#666;"></div>');
                var qrTimer = null, qrIndex = 0;
                $box.append($qr).append($progress);

                function renderQr(chunks) {
                    $qr.empty().qrcode({data: chunks[qrIndex], errorCorrectionLevel: 'L', cellSize: 3});
                    if (chunks.length > 1) $progress.text('{{ lang._("QR chunk") }} ' + (qrIndex + 1) + ' / ' + chunks.length);
                    else $progress.empty();
                }

                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_INFO,
                    title: "{{ lang._('AmneziaVPN Client QR Code') }}",
                    message: $box,
                    onshown: function () {
                        try {
                            var chunks = data.amnezia_qr_chunks || [];
                            if (!Array.isArray(chunks) || chunks.length === 0) throw new Error("{{ lang._('Missing AmneziaVPN QR payload') }}");
                            renderQr(chunks);
                            if (chunks.length > 1) {
                                qrTimer = setInterval(function () {
                                    qrIndex = (qrIndex + 1) % chunks.length;
                                    renderQr(chunks);
                                }, 1000);
                            }
                        } catch (e) {
                            $qr.empty().append($('<div class="alert alert-danger"></div>').text(e.message || "{{ lang._('QR generation failed') }}"));
                        }
                    },
                    onhide: function () { if (qrTimer !== null) clearInterval(qrTimer); },
                    buttons: [{label: "{{ lang._('Close') }}", action: function(d){d.close();}}]
                });
            });
        }

        function downloadPeerConfig(uuid) {
            fetchPeerClientConfig(uuid, function (data) {
                var config;
                try { config = decodePeerClientConfig(data); }
                catch (e) { alert(e.message || "{{ lang._('Unable to decode client configuration') }}"); return; }
                var blob = new Blob([config], {type: 'application/octet-stream'});
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = data.name || 'amneziawg-client.conf';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
            });
        }

        $("#{{formGridPeer['table_id']}}").UIBootgrid(
            {   'search': '/api/amneziawg/peer/search_item',
                'get':    '/api/amneziawg/peer/get_item/',
                'set':    '/api/amneziawg/peer/set_item/',
                'add':    '/api/amneziawg/peer/add_item/',
                'del':    '/api/amneziawg/peer/del_item/',
                'toggle': '/api/amneziawg/peer/toggle_item/',
                'commands': {
                    qrcode: {
                        filter: function (cell) { return cell.getData().client_config === 'ready'; },
                        method: function (event, cell) { showPeerQr(cell.getData().uuid); },
                        classname: 'fa fa-qrcode fa-fw',
                        title: "{{ lang._('Show client QR code') }}",
                        sequence: 1
                    },
                    download: {
                        filter: function (cell) { return cell.getData().client_config === 'ready'; },
                        method: function (event, cell) { downloadPeerConfig(cell.getData().uuid); },
                        classname: 'fa fa-download fa-fw',
                        title: "{{ lang._('Download client .conf') }}",
                        sequence: 2
                    }
                },
                'options': {'formatters': {
                    'peerstatus': function (column, row) {
                        switch (row.runtime) {
                            case 'active': return '<span class=\"label label-success\">{{ lang._('active') }}</span>';
                            case 'inactive': return '<span class=\"label label-warning\">{{ lang._('inactive') }}</span>';
                            case 'never': return '<span class=\"label label-default\">{{ lang._('never') }}</span>';
                            case 'stopped': return '<span class=\"label label-danger\">{{ lang._('stopped') }}</span>';
                            default: return '<span class=\"label label-default\">?</span>';
                        }
                    },
                    'peerhandshake': function (column, row) {
                        var ts = parseInt(row.latest_handshake || 0, 10);
                        if (!ts) return 'never';
                        var sec = Math.max(0, Math.floor(Date.now() / 1000) - ts);
                        if (sec < 60) return sec + 's ago';
                        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
                        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
                        return Math.floor(sec / 86400) + 'd ago';
                    }
                }} }
        );

        // Keep the framework-managed peer.server field intact.  A separate
        // selector provides the UI and mirrors its value back to the real field.
        // Replacing the framework field breaks subsequent dialog mappings.
        var $peerServerField = $('#{{formGridPeer['edit_dialog_id']}}').find('[id="peer.server"]');
        var $peerServerSelect = $('<select class="form-control" id="peer.server_selector"></select>');

        if ($peerServerField.length) {
            $peerServerField.hide();
            $peerServerSelect.insertAfter($peerServerField);

            $peerServerSelect.on('change', function () {
                $peerServerField.val($(this).val());
            });

            $('#{{formGridPeer['edit_dialog_id']}}').on('shown.bs.modal', function () {
                var current = $peerServerField.val() || '';
                $peerServerSelect.empty();
                $peerServerSelect.append(
                    $('<option>').val('').text('{{ lang._("Select server") }}')
                );

                ajaxGet('/api/amneziawg/peer/servers', {}, function (data) {
                    (data.rows || []).forEach(function (row) {
                        $peerServerSelect.append(
                            $('<option>').val(row.uuid).text(row.name + ' — ' + row.interface)
                        );
                    });
                    $peerServerSelect.val(current);
                });
            });
        }

        // Client provisioning for a server peer. The private key is never
        // stored in config.xml; it is submitted once and persisted by the API
        // in /usr/local/etc/amnezia/peer-<uuid>.key with mode 0600.
        var $peerDialog = $('#{{formGridPeer['edit_dialog_id']}}');
        var $peerPk = $peerDialog.find('input[id="peer.public_key"]');
        if ($peerPk.length) {
            var $clientPrivate = $('<input type="hidden" id="peer.client_private_key" name="peer[client_private_key]" value="">');
            $peerPk.after($clientPrivate);
            var $peerTools = $('<div class="btn-group" style="margin-top:4px;"></div>');
            var $genClient = $('<button type="button" class="btn btn-xs btn-default"><i class="fa fa-gear fa-fw"></i> {{ lang._('Generate Client Keys') }}</button>');
            $peerTools.append($genClient).insertAfter($clientPrivate);

            var $peerPsk = $peerDialog.find('input[id="peer.preshared_key"]');

            $peerDialog.on('show.bs.modal', function () { $clientPrivate.val(''); });

            $genClient.click(function () {
                ajaxGet('/api/amneziawg/peer/gen_client_keys', {}, function (data) {
                    if (data.status === 'ok') {
                        $peerPk.val(data.public_key);
                        $peerPsk.val(data.preshared_key);
                        $clientPrivate.val(data.private_key);
                        BootstrapDialog.show({
                            type: BootstrapDialog.TYPE_WARNING,
                            title: "{{ lang._('New Client Keys') }}",
                            message: "{{ lang._('Save this peer first. Then use the QR Code or Download .conf commands in the Server Peers table. Saving installs the newly generated client keypair and preshared key on the server.') }}",
                            buttons: [{label: "{{ lang._('Close') }}", action: function(d){d.close();}}]
                        });
                    } else alert(data.message || "{{ lang._('Client key generation failed') }}");
                });
            });
        }

        // Server key generation; the server public key is shown for copying to clients.
        var $serverPk = $('#{{formGridServer['edit_dialog_id']}} input[id="server.private_key"]');
        if ($serverPk.length) {
            $('<button type="button" class="btn btn-xs btn-default" style="margin-top:4px;"><i class="fa fa-gear"></i> {{ lang._('Generate Keypair') }}</button>')
                .insertAfter($serverPk).click(function () {
                    ajaxGet('/api/amneziawg/server/gen_key_pair', {}, function (data) {
                        if (data.status === 'ok') {
                            $serverPk.val(data.private_key);
                            BootstrapDialog.show({type: BootstrapDialog.TYPE_INFO, title: "{{ lang._('Server Public Key') }}",
                                message: '<code style="word-break:break-all;">' + data.public_key + '</code>',
                                buttons: [{label: "{{ lang._('Close') }}", action: function(d){d.close();}}]});
                        } else alert(data.message || '{{ lang._("Key generation failed") }}');
                    });
                });
        }


        var $serverHpk = $('#{{formGridServer['edit_dialog_id']}} input[id="server.header_protection_key"]');
        if ($serverHpk.length) {
            $('<button type="button" class="btn btn-xs btn-default" style="margin-top:4px;"><i class="fa fa-random"></i> {{ lang._('Generate HP Key') }}</button>')
                .insertAfter($serverHpk).click(function () {
                    ajaxGet('/api/amneziawg/server/gen_key_pair', {}, function (data) {
                        if (data.status === 'ok') $serverHpk.val(data.private_key);
                        else alert(data.message || '{{ lang._("Key generation failed") }}');
                    });
                });
        }

        $('#{{formGridServer['edit_dialog_id']}}').on('shown.bs.modal', function () {
            var el = document.createElement('textarea');
            ['i1','i2','i3','i4','i5'].forEach(function (f) {
                var $input = $('[id="server.' + f + '"]'); var v = $input.val();
                if (!v || v.indexOf('&') === -1) return;
                var prev=v; while(true){el.innerHTML=prev; var dec=el.value; if(dec===prev)break; prev=dec;} $input.val(prev);
            });
        });

        // Dialog post-load hooks: decode CPS fields, inject pending import data
        $('#{{formGridInstance['edit_dialog_id']}}').on('shown.bs.modal', function () {
            if (window._awgImportData) {
                var data = window._awgImportData;
                window._awgImportData = null;
                Object.keys(data).forEach(function (f) {
                    if (data[f] !== undefined && data[f] !== '') {
                        var $field = $('[id="instance.' + f + '"]');
                        if (f === 'random_trailers' || f === 'disable_cookies') {
                            $field.prop('checked', String(data[f]) === '1');
                        } else {
                            $field.val(data[f]);
                        }
                    }
                });
            }
            decodeIFields();
        });

        // ── Keypair generation (button injected into the dialog) ──────
        var $pkInput = $('#{{formGridInstance['edit_dialog_id']}} input[id="instance.private_key"]');
        if ($pkInput.length) {
            $('<button type="button" id="keygen" class="btn btn-xs btn-default" style="margin-top:4px;">' +
              '<i class="fa fa-gear"></i> {{ lang._('Generate Keypair') }}</button>')
                .insertAfter($pkInput)
                .click(function () {
                    ajaxGet("/api/amneziawg/instance/gen_key_pair", {}, function (data) {
                        if (data.status && data.status === 'ok') {
                            $pkInput.val(data.private_key);
                            BootstrapDialog.show({
                                type:    BootstrapDialog.TYPE_INFO,
                                title:   "{{ lang._('Public Key') }}",
                                message: "{{ lang._('Share this public key with the server administrator:') }}" +
                                         '<br><br><code style="word-break:break-all;">' + data.public_key + '</code>',
                                buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                            });
                        } else {
                            alert(data.message || "{{ lang._('Key generation failed') }}");
                        }
                    });
                });
        }


        var $clientHpk = $('#{{formGridInstance['edit_dialog_id']}} input[id="instance.header_protection_key"]');
        if ($clientHpk.length) {
            $('<button type="button" class="btn btn-xs btn-default" style="margin-top:4px;"><i class="fa fa-random"></i> {{ lang._('Generate HP Key') }}</button>')
                .insertAfter($clientHpk).click(function () {
                    ajaxGet('/api/amneziawg/instance/gen_key_pair', {}, function (data) {
                        if (data.status === 'ok') $clientHpk.val(data.private_key);
                        else alert(data.message || '{{ lang._("Key generation failed") }}');
                    });
                });
        }

        // ── Apply ─────────────────────────────────────────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                _statusPaused = true;
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/amneziawg/general/set", 'frm_general_settings', function () {
                    dfObj.resolve();
                }, true, function () {
                    dfObj.resolve();
                });
                return dfObj;
            },
            onAction: function (data, status) {
                if (data && data.status === 'disabled') {
                    _statusPaused = false;
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_INFO,
                        title:   "{{ lang._('AmneziaWG') }}",
                        message: "{{ lang._('AmneziaWG is disabled. Enable it on the General tab and apply again.') }}",
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                    return;
                }
                if (data && data.result === 'ok') {
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    $('#{{formGridServer['table_id']}}').bootgrid('reload');
                    $('#{{formGridPeer['table_id']}}').bootgrid('reload');
                    setTimeout(function () { _statusPaused = false; updateStatus(); }, 2000);
                } else {
                    _statusPaused = false;
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_DANGER,
                        title:   "{{ lang._('Error') }}",
                        message: "{{ lang._('Error reconfiguring AmneziaWG service.') }}" +
                                 (data && data.output ? '<br><code>' + data.output + '</code>' : ''),
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                }
            }
        });

        // ── Status badge ──────────────────────────────────────────────
        var _statusTimer = null;
        var _statusPaused = false;

        function updateStatus() {
            if (_statusPaused) return;
            ajaxGet("/api/amneziawg/service/tunnel_status", {}, function (data) {
                var summary = data.summary || {};
                var clients = summary.clients || {running: 0, total: 0};
                var servers = summary.servers || {running: 0, total: 0};
                var running = (parseInt(clients.running || 0, 10) + parseInt(servers.running || 0, 10)) > 0;

                function runtimeBadge(selector, title, state) {
                    var up = parseInt(state.running || 0, 10), total = parseInt(state.total || 0, 10);
                    var cls = total === 0 ? 'label-default' : (up === total ? 'label-success' : (up === 0 ? 'label-danger' : 'label-warning'));
                    $(selector)
                        .removeClass('label-success label-danger label-warning label-default')
                        .addClass(cls)
                        .text(title + ': ' + up + '/' + total + ' running');
                }
                runtimeBadge('#badge_clients', 'Clients', clients);
                runtimeBadge('#badge_servers', 'Servers', servers);

                var health = data.health || {};
                var healthState = health.state || 'waiting';
                var healthClass = healthState === 'online' ? 'label-success'
                    : (healthState === 'offline' ? 'label-danger'
                    : (healthState === 'disabled' || healthState === 'stopped' ? 'label-default'
                    : 'label-warning'));
                var healthTitle = '';
                if (Array.isArray(health.clients)) {
                    healthTitle = health.clients.map(function (h) {
                        var suffix = (h.state === 'offline' && h.failures > 0 && h.failures < 3) ? ' ' + h.failures + '/3' : '';
                        return h.name + ': ' + h.state + suffix;
                    }).join('; ');
                }
                $('#badge_health')
                    .removeClass('label-success label-danger label-warning label-default')
                    .addClass(healthClass)
                    .attr('title', healthTitle)
                    .text(health.label || 'Health: waiting');

                if (!_statusPaused) {
                    $('#btnStart').prop('disabled', running);
                    $('#btnStop, #btnRestart').prop('disabled', !running);
                }
            });
        }
        updateStatus();
        _statusTimer = setInterval(updateStatus, 10000);

        // Health probes are per client and run independently. Refresh the
        // Clients grid so each Health cell follows only that tunnel's cache.
        var _clientGridTimer = setInterval(function () {
            if (!_statusPaused && $('#{{formGridInstance['table_id']}}').is(':visible')) {
                $('#{{formGridInstance['table_id']}}').bootgrid('reload');
            }
        }, 30000);

        // ── Start / Stop / Restart (service level: all tunnels) ───────
        function serviceAction(action, confirmMsg) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // Pause status polling to avoid configd contention
            _statusPaused = true;

            var $btns = $('#btnStart, #btnStop, #btnRestart').prop('disabled', true);
            var $btn = $('#btn' + action.charAt(0).toUpperCase() + action.slice(1));
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/amneziawg/service/' + action,
                type:     'POST',
                dataType: 'json',
                timeout:  65000,
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        _statusPaused = false;
                        updateStatus();
                        $btns.prop('disabled', false);
                    }, 2000);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    _statusPaused = false;
                    if (xhr.statusText === 'timeout') {
                        alert('{{ lang._("Request timed out. Check tunnel status manually.") }}');
                    } else {
                        alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                    }
                }
            });
        }

        $('#btnStart').click(function () {
            serviceAction('start', null);
        });

        $('#btnStop').click(function () {
            serviceAction('stop', '{{ lang._("Stop AmneziaWG? All active tunnels will be terminated.") }}');
        });

        $('#btnRestart').click(function () {
            serviceAction('restart', null);
        });

        // ── Import .conf parser ───────────────────────────────────────
        // Fills the edit dialog if it is open, otherwise stashes the parsed
        // fields and opens the Add dialog (consumed in shown.bs.modal above).
        $("#importParseBtn").click(function () {
            ajaxCall("/api/amneziawg/import/parse", {config: $("#importConfigText").val()}, function (data) {
                if (data.status === 'ok') {
                    var fields = ['private_key','address','dns','mtu',
                                  'jc','jmin','jmax','s1','s2','s3','s4','h1','h2','h3','h4',
                                  'header_protection_key','content_padding_addition',
                                  'rekey_after_time','rekey_timeout','reject_after_time','keepalive_timeout','max_handshake_attempts',
                                  'random_trailers','disable_cookies',
                                  'i1','i2','i3','i4','i5',
                                  'peer_public_key','peer_preshared_key','peer_endpoint',
                                  'peer_allowed_ips','peer_persistent_keepalive'];
                    var parsed = {};
                    fields.forEach(function (f) {
                        if (data[f] !== undefined && data[f] !== '') {
                            parsed[f] = data[f];
                        }
                    });
                    $("#importModal").modal('hide');
                    if ($('#{{formGridInstance['edit_dialog_id']}}').hasClass('in')) {
                        // Dialog already open — fill fields directly
                        Object.keys(parsed).forEach(function (f) {
                            var $field = $('[id="instance.' + f + '"]');
                            if (f === 'random_trailers' || f === 'disable_cookies') {
                                $field.prop('checked', String(parsed[f]) === '1');
                            } else {
                                $field.val(parsed[f]);
                            }
                        });
                        decodeIFields();
                    } else {
                        // Stash and open the Add dialog.
                        // 26.x (Tabulator) renders the add button as .command-add inside
                        // the grid container div; legacy bootgrid used [data-action="add"].
                        window._awgImportData = parsed;
                        $('#{{formGridInstance['table_id']}}')
                            .find('button.command-add, button[data-action="add"]').first().click();
                    }
                } else {
                    alert(data.message || "{{ lang._('Parse error') }}");
                }
            });
        });

        // ── Diagnostics tab ──────────────────────────────────────────
        // Multi-instance: tunnel selector feeds diagnostics.
        function selectedDiagIface() {
            return $('#diagIface').val() || '';
        }

        function selectedDiagUuid() {
            return $('#diagIface option:selected').attr('data-uuid') || '';
        }

        function loadDiagIfaceList() {
            var dfObj = new $.Deferred();
            ajaxCall('/api/amneziawg/instance/search_item', {}, function (data) {
                var rows = (data && data.rows) ? data.rows : [];
                rows.sort(function (a, b) {
                    return parseInt(a.interface_number || 0, 10) - parseInt(b.interface_number || 0, 10);
                });
                var prev = selectedDiagIface();
                var $sel = $('#diagIface').empty();
                rows.forEach(function (row) {
                    var iface = 'awg' + (row.interface_number || '0');
                    var label = iface + ' — ' + (row.name || '') + (row.enabled !== '1' ? ' ({{ lang._("disabled") }})' : '');
                    $sel.append($('<option>').val(iface).text(label)
                        .attr('data-mode', 'client')
                        .attr('data-uuid', row.uuid || ''));
                });
                ajaxCall('/api/amneziawg/server/search_item', {}, function (srvData) {
                    var servers = (srvData && srvData.rows) ? srvData.rows : [];
                    servers.sort(function (a, b) { return parseInt(a.interface_number || 0, 10) - parseInt(b.interface_number || 0, 10); });
                    servers.forEach(function (row) {
                        var iface = 'awg' + (row.interface_number || '0');
                        var label = iface + ' — ' + (row.name || '') + ' [server]' + (row.enabled !== '1' ? ' ({{ lang._("disabled") }})' : '');
                        $sel.append($('<option>').val(iface).text(label)
                            .attr('data-mode', 'server')
                            .attr('data-uuid', ''));
                    });
                    if (prev && $sel.find('option[value="' + prev + '"]').length) $sel.val(prev);
                    dfObj.resolve();
                });
            });
            return dfObj;
        }

        $('#diagIface').change(function () {
            loadDiagnostics();
        });

        function diagBadge(text, kind) {
            var cls = kind === 'ok' ? 'label-success'
                : (kind === 'bad' ? 'label-danger'
                : (kind === 'warn' ? 'label-warning' : 'label-default'));
            return '<span class="label ' + cls + '">' + $('<div>').text(text).html() + '</span>';
        }

        function loadDiagnostics() {
            $('#diagLoading').show();
            $('#diagError').hide();
            var params = {};
            if (selectedDiagIface() !== '') {
                params['interface'] = selectedDiagIface();
            }
            ajaxGet('/api/amneziawg/service/diagnostics', params, function (data) {
                $('#diagLoading').hide();
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = (data.status === 'running');
                $('#diag_status').html(running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + (data.status || 'unknown') + '</span>');
                $('#diag_interface').text(data.interface || '-');
                $('#diag_ip').text(data.ip || '-');
                $('#diag_mtu').text(data.mtu || '-');
                $('#diag_pubkey').text(data.public_key || '-');
                $('#diag_listen_port').text(data.listen_port || '-');
                var peers = Array.isArray(data.peers) ? data.peers : [];
                var $peerRows = $('#diag_peer_rows').empty();
                if (!peers.length) {
                    $peerRows.append('<tr><td colspan="2" class="text-muted">{{ lang._("No peers reported by awg") }}</td></tr>');
                } else {
                    peers.forEach(function (peer, idx) {
                        var title = peers.length > 1 ? ('Peer ' + (idx + 1)) : '{{ lang._("Peer") }}';
                        var safe = function (v) { return $('<div>').text(v || '-').html(); };
                        $peerRows.append(
                            '<tr><th colspan="2">' + title + '</th></tr>' +
                            '<tr><td style="width:200px;">{{ lang._("Public Key") }}</td><td style="word-break:break-all;">' + safe(peer.public_key) + '</td></tr>' +
                            '<tr><td>{{ lang._("Endpoint") }}</td><td>' + safe(peer.endpoint) + '</td></tr>' +
                            '<tr><td>{{ lang._("Allowed IPs") }}</td><td>' + safe(peer.allowed_ips) + '</td></tr>' +
                            '<tr><td>{{ lang._("Latest Handshake") }}</td><td>' + safe(peer.latest_handshake || 'never') + '</td></tr>' +
                            '<tr><td>{{ lang._("Persistent Keepalive") }}</td><td>' + safe(peer.persistent_keepalive) + '</td></tr>' +
                            '<tr><td>{{ lang._("Transfer") }}</td><td>RX ' + safe(peer.transfer_rx || '0 B') + ' / TX ' + safe(peer.transfer_tx || '0 B') + '</td></tr>'
                        );
                    });
                }
                $('#diag_transfer_rx').text(data.transfer_rx || '0 B');
                $('#diag_transfer_tx').text(data.transfer_tx || '0 B');
                $('#diag_netstat_rx').text((data.bytes_in_hr || '0 B') + ' (' + (data.packets_in || 0) + ' pkts)');
                $('#diag_netstat_tx').text((data.bytes_out_hr || '0 B') + ' (' + (data.packets_out || 0) + ' pkts)');
                $('#diag_uptime').text(data.uptime || '-');

                var connectivity = data.connectivity || 'unknown';
                var healthClass = connectivity === 'online' ? 'label-success'
                    : (connectivity === 'offline' ? 'label-danger'
                    : (connectivity === 'stopped' ? 'label-default' : 'label-warning'));
                $('#diag_health_status').html('<span class="label ' + healthClass + '">' + connectivity + '</span>');
                $('#diag_health_enabled').html(diagBadge(data.health_monitor_enabled ? 'enabled' : 'disabled', data.health_monitor_enabled ? 'ok' : 'neutral'));
                $('#diag_health_target').text(data.health_probe_target || data.health_target_configured || '-');
                $('#diag_health_latency').text(data.health_latency_ms !== null && data.health_latency_ms !== undefined ? data.health_latency_ms + ' ms' : '-');
                var failures = parseInt(data.health_failures || 0, 10); $('#diag_health_failures').html(diagBadge(String(failures), failures >= 3 ? 'bad' : (failures > 0 ? 'warn' : 'ok')));
                $('#diag_health_message').text(data.health_message || '-');
                $('#diag_assignment').html(data.assignment ? diagBadge(data.assignment + (data.assignment_enabled ? ' (enabled)' : ' (disabled)'), data.assignment_enabled ? 'ok' : 'warn') : '-');
                $('#diag_gateway_sync').html(diagBadge(data.gateway_health_sync_enabled ? (data.gateway_sync_ready ? 'enabled / ready' : 'enabled / not ready') : 'disabled', data.gateway_health_sync_enabled ? (data.gateway_sync_ready ? 'ok' : 'warn') : 'neutral'));
                $('#diag_gateway').text(data.native_gateway_name
                    ? data.native_gateway_name + ' (' + (data.native_gateway_address || '-') + ')'
                    : (data.native_gateway_ambiguous ? 'ambiguous' : '-'));
                $('#diag_gateway_monitor').html(data.native_gateway_name
                    ? diagBadge(data.native_gateway_monitor_disabled ? 'disabled (plugin health source)' : 'enabled', data.native_gateway_monitor_disabled ? 'ok' : 'warn')
                    : '-');
                $('#diag_gateway_force').html(data.native_gateway_name
                    ? diagBadge(data.native_gateway_force_down ? 'forced down' : 'up', data.native_gateway_force_down ? 'bad' : 'ok')
                    : '-');
                var gwStatus = data.native_gateway_status_text || data.native_gateway_status || '-'; $('#diag_gateway_status').html(gwStatus === '-' ? '-' : diagBadge(gwStatus, /online|up/i.test(gwStatus) ? 'ok' : (/offline|down/i.test(gwStatus) ? 'bad' : 'warn')));
                $('#diag_pf_route').html(diagBadge(data.pf_route_to_present ? 'present' : 'not found', data.pf_route_to_present ? 'ok' : 'bad'));
                $('#btnHealthProbe').prop('disabled', !selectedDiagUuid());
            });
        }

        var _diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagIfaceList().done(loadDiagnostics);
            if (!_diagAutoRefresh) {
                _diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });
{% if section == 'diagnostics' %}
        // Direct /ui/amneziawg/diagnostics load: the tab is already active,
        // therefore Bootstrap does not emit shown.bs.tab.
        loadDiagIfaceList().done(loadDiagnostics);
        if (!_diagAutoRefresh) {
            _diagAutoRefresh = setInterval(function () {
                if ($('#diagnostics').hasClass('active')) {
                    loadDiagnostics();
                }
            }, 30000);
        }
{% endif %}
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });

        $('#btnHealthProbe').click(function () {
            var uuid = selectedDiagUuid();
            if (!uuid) {
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/amneziawg/service/health/' + uuid,
                type: 'POST',
                dataType: 'json',
                timeout: 10000,
                success: function (data) {
                    if (!data) {
                        alert("{{ lang._('Health probe failed') }}");
                    } else if (data.result === 'failed') {
                        BootstrapDialog.show({
                            type: BootstrapDialog.TYPE_WARNING,
                            title: "{{ lang._('Health probe') }}",
                            message: $('<div>').text(data.message || "{{ lang._('Health probe failed') }}").html(),
                            buttons: [{label: "{{ lang._('Close') }}", action: function(d){ d.close(); }}]
                        });
                    } else if (data.result === 'waiting') {
                        BootstrapDialog.show({
                            type: BootstrapDialog.TYPE_INFO,
                            title: "{{ lang._('Health probe') }}",
                            message: $('<div>').text(data.message || "{{ lang._('Health probe is waiting for routing') }}").html(),
                            buttons: [{label: "{{ lang._('Close') }}", action: function(d){ d.close(); }}]
                        });
                    } else if (data.result !== 'ok') {
                        alert(data.message || "{{ lang._('Health probe failed') }}");
                    }
                    loadDiagnostics();
                },
                error: function () {
                    alert("{{ lang._('Health probe request failed') }}");
                },
                complete: function () {
                    $btn.prop('disabled', !selectedDiagUuid());
                }
            });
        });


        // ── Log tab ─────────────────────────────────────────────────
        function loadLog() {
            $('#logServiceRefreshBtn, #logWatchdogRefreshBtn').prop('disabled', true);
            $('#logServiceContent, #logWatchdogContent').text('{{ lang._("Loading...") }}');
            $.post('/api/amneziawg/service/log', null, function (data) {
                var raw = (data && data.log) || '';
                var lines = raw ? raw.split(/\r?\n/) : [];
                var watchdog = lines.filter(function (line) { return line.indexOf('WATCHDOG:') !== -1; });
                var service = lines.filter(function (line) { return line.indexOf('WATCHDOG:') === -1; });
                $('#logServiceContent').text(service.filter(Boolean).join('\n') || '{{ lang._("Service log is empty") }}');
                $('#logWatchdogContent').text(watchdog.filter(Boolean).join('\n') || '{{ lang._("Watchdog log is empty") }}');
                $('#logServiceRefreshBtn, #logWatchdogRefreshBtn').prop('disabled', false);
            }, 'json').fail(function () {
                $('#logServiceContent, #logWatchdogContent').text('{{ lang._("Failed to load log") }}');
                $('#logServiceRefreshBtn, #logWatchdogRefreshBtn').prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', loadLog);
        $('#logServiceRefreshBtn, #logWatchdogRefreshBtn').click(loadLog);

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var diagDone = $.Deferred(), logDone = $.Deferred();
            var diagData = {}, logText = '';

            ajaxGet('/api/amneziawg/service/diagnostics',
                    selectedDiagIface() !== '' ? {interface: selectedDiagIface()} : {},
                    function (data) {
                diagData = data;
                diagDone.resolve();
            });
            $.post('/api/amneziawg/service/log', null, function (data) {
                logText = (data && data.log) || '';
                logDone.resolve();
            }, 'json').fail(function () { logDone.resolve(); });

            $.when(diagDone, logDone).done(function () {
                var info = '=== opnsense-awg Debug Info ===\n'
                    + 'Date: ' + new Date().toISOString() + '\n\n'
                    + '=== Diagnostics ===\n'
                    + JSON.stringify(diagData, null, 2) + '\n\n'
                    + '=== Log (last 150 lines) ===\n'
                    + logText + '\n';
                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
            });
        });

        // ── Validate config ─────────────────────────────────────────
        $('#btnValidate').click(function () {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            $.ajax({
                url: '/api/amneziawg/service/validate',
                type: 'POST',
                dataType: 'json',
                timeout: 15000,
                success: function (data) {
                    $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}');
                    var ok = (data.result === 'ok');
                    BootstrapDialog.show({
                        type: ok ? BootstrapDialog.TYPE_SUCCESS : BootstrapDialog.TYPE_DANGER,
                        title: '{{ lang._("Config Validation") }}',
                        message: ok ? '{{ lang._("Configuration is valid.") }}' : ('{{ lang._("Validation failed:") }} ' + (data.message || '')),
                        buttons: [{ label: '{{ lang._("Close") }}', action: function (d) { d.close(); } }]
                    });
                },
                error: function () {
                    $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}');
                }
            });
        });

        // ── Tab hash ──────────────────────────────────────────────────
        if (window.location.hash !== "" && $('#maintabs a[href="' + window.location.hash + '"]').length) {
            $('#maintabs a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
{% if section == 'general' %}
    <li class="active"><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
{% elseif section == 'server' %}
    <li class="active"><a data-toggle="tab" href="#servers">{{ lang._('Servers') }}</a></li>
    <li><a data-toggle="tab" href="#peers">{{ lang._('Server Peers') }}</a></li>
{% elseif section == 'clients' %}
    <li class="active"><a data-toggle="tab" href="#tunnels">{{ lang._('Clients') }}</a></li>
{% elseif section == 'diagnostics' %}
    <li class="active"><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
{% endif %}
</ul>

<div class="tab-content content-box">

    <div id="tunnels" class="tab-pane fade in{% if section == 'clients' %} active{% endif %}"{% if section != 'clients' %} style="display:none;"{% endif %}>
        <div style="padding: 10px 15px 6px;">
            <button class="btn btn-xs btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import .conf') }}
            </button>
        </div>

        {{ partial('layout_partials/base_bootgrid_table', formGridInstance) }}
    </div>

    <div id="servers" class="tab-pane fade in{% if section == 'server' %} active{% endif %}"{% if section != 'server' %} style="display:none;"{% endif %}>
        <div class="alert alert-info" style="margin: 10px 15px;">
            {{ lang._('Server interfaces use the same awgN namespace as clients. Add peers on the Server Peers tab, then Apply.') }}
        </div>
        {{ partial('layout_partials/base_bootgrid_table', formGridServer) }}
    </div>

    <div id="peers" class="tab-pane fade in"{% if section != 'server' %} style="display:none;"{% endif %}>
        <div class="alert alert-info" style="margin: 10px 15px;">
            {{ lang._('Each peer belongs to one server. Endpoint is intentionally absent on server-side peers.') }}
        </div>
        {{ partial('layout_partials/base_bootgrid_table', formGridPeer) }}
    </div>

    <div id="general" class="tab-pane fade in{% if section == 'general' %} active{% endif %}"{% if section != 'general' %} style="display:none;"{% endif %}>
        <div style="padding: 8px 15px; border-bottom: 1px solid #ddd; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
            <div>
                <span id="badge_clients" class="label label-default">Clients: ...</span>
                <span id="badge_servers" class="label label-default">Servers: ...</span>
                <span id="badge_health" class="label label-default" title="">Health: ...</span>
            </div>
            <div style="width: 1px; height: 22px; background: #ddd;"></div>
            <div style="display: flex; gap: 4px;">
                <button id="btnStart" class="btn btn-xs btn-success" title="{{ lang._('Start all enabled AmneziaWG instances') }}"><i class="fa fa-play fa-fw"></i> {{ lang._('Start All') }}</button>
                <button id="btnStop" class="btn btn-xs btn-danger" title="{{ lang._('Stop all running AmneziaWG instances') }}"><i class="fa fa-stop fa-fw"></i> {{ lang._('Stop All') }}</button>
                <button id="btnRestart" class="btn btn-xs btn-warning" title="{{ lang._('Restart all running AmneziaWG instances without saving pending form changes') }}"><i class="fa fa-refresh fa-fw"></i> {{ lang._('Restart All') }}</button>
            </div>
        </div>
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
    </div>

    <div id="diagnostics" class="tab-pane fade in{% if section == 'diagnostics' %} active{% endif %}"{% if section != 'diagnostics' %} style="display:none;"{% endif %}>
        <div style="padding: 15px;">
            <div style="margin-bottom: 10px; display: flex; gap: 6px; align-items: center;">
                <select id="diagIface" class="form-control" style="width: auto; min-width: 180px; display: inline-block;"
                        title="{{ lang._('Tunnel to inspect') }}"></select>
                <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                    <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
                </button>
                <button id="btnHealthProbe" class="btn btn-sm btn-default">
                    <i class="fa fa-heartbeat"></i> {{ lang._('Test Health') }}
                </button>
                <button id="btnValidate" class="btn btn-sm btn-default">
                    <i class="fa fa-check-circle"></i> {{ lang._('Validate Config') }}
                </button>
                <button id="btnCopyDebug" class="btn btn-sm btn-default">
                    <i class="fa fa-clipboard"></i> {{ lang._('Copy Debug Info') }}
                </button>
            </div>
            <div id="diagError" class="alert alert-danger" style="display: none;"></div>
            <div id="diagLoading" style="display: none;">
                <i class="fa fa-spinner fa-spin"></i> {{ lang._('Loading...') }}
            </div>

            <table class="table table-striped table-condensed">
                <thead>
                    <tr><th colspan="2">{{ lang._('Interface') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td style="width:200px;">{{ lang._('Status') }}</td><td id="diag_status">-</td></tr>
                    <tr><td>{{ lang._('Interface') }}</td><td id="diag_interface">-</td></tr>
                    <tr><td>{{ lang._('IP Address') }}</td><td id="diag_ip">-</td></tr>
                    <tr><td>{{ lang._('MTU') }}</td><td id="diag_mtu">-</td></tr>
                    <tr><td>{{ lang._('Public Key') }}</td><td id="diag_pubkey" style="word-break:break-all;">-</td></tr>
                    <tr><td>{{ lang._('Listen Port') }}</td><td id="diag_listen_port">-</td></tr>
                    <tr><td>{{ lang._('Uptime') }}</td><td id="diag_uptime">-</td></tr>
                </tbody>
                <thead>
                    <tr><th colspan="2">{{ lang._('Health / Native Gateway') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td>{{ lang._('Health Monitor') }}</td><td id="diag_health_enabled">-</td></tr>
                    <tr><td>{{ lang._('Connectivity') }}</td><td id="diag_health_status">-</td></tr>
                    <tr><td>{{ lang._('Probe Target') }}</td><td id="diag_health_target">-</td></tr>
                    <tr><td>{{ lang._('Latency') }}</td><td id="diag_health_latency">-</td></tr>
                    <tr><td>{{ lang._('Consecutive Failures') }}</td><td id="diag_health_failures">-</td></tr>
                    <tr><td>{{ lang._('Health Message') }}</td><td id="diag_health_message">-</td></tr>
                    <tr><td>{{ lang._('OPNsense Assignment') }}</td><td id="diag_assignment">-</td></tr>
                    <tr><td>{{ lang._('Gateway Health Sync') }}</td><td id="diag_gateway_sync">-</td></tr>
                    <tr><td>{{ lang._('Native Gateway') }}</td><td id="diag_gateway">-</td></tr>
                    <tr><td>{{ lang._('Native Gateway Monitoring') }}</td><td id="diag_gateway_monitor">-</td></tr>
                    <tr><td>{{ lang._('Force Down') }}</td><td id="diag_gateway_force">-</td></tr>
                    <tr><td>{{ lang._('Gateway Status') }}</td><td id="diag_gateway_status">-</td></tr>
                    <tr><td>{{ lang._('PF route-to') }}</td><td id="diag_pf_route">-</td></tr>
                </tbody>
                <thead>
                    <tr><th colspan="2">{{ lang._('Peer') }}</th></tr>
                </thead>
                <tbody id="diag_peer_rows">
                    <tr><td colspan="2" class="text-muted">{{ lang._('Select an interface to load peer details') }}</td></tr>
                </tbody>
                <thead>
                    <tr><th colspan="2">{{ lang._('Traffic') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td>{{ lang._('Transfer RX (awg)') }}</td><td id="diag_transfer_rx">-</td></tr>
                    <tr><td>{{ lang._('Transfer TX (awg)') }}</td><td id="diag_transfer_tx">-</td></tr>
                    <tr><td>{{ lang._('Netstat RX') }}</td><td id="diag_netstat_rx">-</td></tr>
                    <tr><td>{{ lang._('Netstat TX') }}</td><td id="diag_netstat_tx">-</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="logs" class="tab-pane fade in"{% if section != 'diagnostics' %} style="display:none;"{% endif %}>
        <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;">
            <ul class="nav nav-pills nav-sm" style="margin:0;">
                <li class="active"><a data-toggle="tab" href="#logService"><i class="fa fa-terminal fa-fw"></i> {{ lang._('Service Log') }}</a></li>
                <li><a data-toggle="tab" href="#logWatchdog"><i class="fa fa-heartbeat fa-fw"></i> {{ lang._('Watchdog Log') }}</a></li>
            </ul>
        </div>
        <div class="tab-content" style="padding: 0 15px 15px;">
            <div id="logService" class="tab-pane fade in active" style="padding-top:10px;">
                <div style="margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                    <button id="logServiceRefreshBtn" class="btn btn-sm btn-default"><i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}</button>
                    <span class="text-muted" style="font-size:12px;">{{ lang._('/var/log/amneziawg.log — service/lifecycle entries, last 150 lines') }}</span>
                </div>
                <pre id="logServiceContent" style="min-height:300px;max-height:550px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;border:1px solid #444;">{{ lang._('Switch to this tab to load log...') }}</pre>
            </div>
            <div id="logWatchdog" class="tab-pane fade in" style="padding-top:10px;">
                <div style="margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                    <button id="logWatchdogRefreshBtn" class="btn btn-sm btn-default"><i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}</button>
                    <span class="text-muted" style="font-size:12px;">{{ lang._('/var/log/amneziawg.log — WATCHDOG entries, last 150 lines') }}</span>
                </div>
                <pre id="logWatchdogContent" style="min-height:300px;max-height:550px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;border:1px solid #444;">{{ lang._('Switch to this tab to load log...') }}</pre>
            </div>
        </div>
    </div>

</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/amneziawg/service/reconfigure'}) }}

{# Edit dialog for tunnel instances #}
{{ partial("layout_partials/base_dialog", ['fields': formDialogInstance, 'id': formGridInstance['edit_dialog_id'], 'label': lang._('Edit Tunnel')]) }}
{{ partial("layout_partials/base_dialog", ['fields': formDialogServer, 'id': formGridServer['edit_dialog_id'], 'label': lang._('Edit Server')]) }}
{{ partial("layout_partials/base_dialog", ['fields': formDialogPeer, 'id': formGridPeer['edit_dialog_id'], 'label': lang._('Edit Server Peer')]) }}

<!-- Debug Info Modal -->
<div class="modal fade" id="debugInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-clipboard"></i> {{ lang._('Debug Info') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">{{ lang._('Copy this information and share it when reporting issues.') }}</p>
                <textarea id="debugInfoContent" class="form-control" rows="20"
                    style="font-family: monospace; font-size: 11px;" readonly></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary"
                    onclick="$('#debugInfoContent').select(); document.execCommand('copy');">
                    <i class="fa fa-clipboard"></i> {{ lang._('Copy to Clipboard') }}
                </button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-upload"></i> {{ lang._('Import AmneziaWG Configuration') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ lang._('Paste the contents of your AmneziaWG client .conf file. A new tunnel dialog will open with all fields filled automatically.') }}
                </p>
                <textarea id="importConfigText" class="form-control" rows="18"
                    style="font-family: monospace; font-size: 12px;"
                    placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.1.2/24&#10;Jc = 4&#10;...&#10;&#10;[Peer]&#10;PublicKey = ...&#10;Endpoint = 1.2.3.4:51820&#10;AllowedIPs = 0.0.0.0/0"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="importParseBtn">
                    <i class="fa fa-magic"></i> {{ lang._('Parse & Fill') }}
                </button>
            </div>
        </div>
    </div>
</div>
