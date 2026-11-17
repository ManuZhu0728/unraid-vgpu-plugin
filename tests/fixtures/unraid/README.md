# Native startup test fixture

`rc.modules` is an unmodified copy of Unraid WebGUI's native script at
[`5b111446d37197b8a601a30a29ac158f3b24919d`](https://github.com/unraid/webgui/blob/5b111446d37197b8a601a30a29ac158f3b24919d/etc/rc.d/rc.modules).
Its SHA256 is `70e506c193d0a65227747ee0a0481ba97e777e19c3d00873c887e1f212b3546e`.
The path/filter/install/depmod sequence also matches the previously collected
7.3.4 diagnostic source. This fixture is test-only and never enters a plugin TXZ.

`tests/native_rc_runner.py` runs it unchanged in a networkless user/mount/PID
namespace. The only writable application data is a randomly created passive
fixture root. Genuine retained Slackware pkgtools handles `installpkg --root`;
`uname` supplies the fixture kernel and `depmod` only records ordering, because
fixture modules are text, not real NVIDIA code. Module metadata and daemon/mdev
results remain explicit passive fixtures. This is native startup integration
coverage, not physical GPU or Unraid acceptance evidence.

No native `rc.modules`, `rc.local`, other plugin, service, or kernel hook is
modified or deployed. Missing tools/namespaces produce an explicit test skip,
never an attempt to execute native startup on the host or NAS.

## Native OS post-hook dispatch

`post-hooks-7.3.4.php` and `post-hooks-7.4.0.php` contain the exact `post_hooks`
function from the native plugin manager at commits
[`31c22f8327cca774dcbddd573dc76f70e87b2f10`](https://github.com/unraid/webgui/blob/31c22f8327cca774dcbddd573dc76f70e87b2f10/emhttp/plugins/dynamix.plugin.manager/scripts/plugin)
and [`b135287c706a30da44f71cc7820615209e83e9a0`](https://github.com/unraid/webgui/blob/b135287c706a30da44f71cc7820615209e83e9a0/emhttp/plugins/dynamix.plugin.manager/scripts/plugin).
The test maps only the hook directory and our backend command into a disposable
root, then executes the actual Bash hook. The rest of the OS updater, network,
GPU commands and services are not executed. These excerpts never enter the TXZ.
They check synchronous hook dispatch, not ordering against the independent
shared Update Helper's notifications.
