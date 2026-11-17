<?php
function post_hooks($error='') {
  global $method, $plugin, $download_only;
  if ($download_only) return;
  $hooks = "/usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks";
  foreach (glob("$hooks/*") as $hook) if (is_executable($hook)) {
    write("Executing hook script: ".basename($hook)."\n");
    run("$hook plugin $method $plugin $error");
  }
}
