<div class="mailstream-item-body">$item.body</div>
{{ if $item.plink }}
<div>Upstream: <a class="mailstream-item-plink" href="$item.plink">$item.plink</a><div>
<div>Local: <a class="mailstream-item-url" href="$item.url">$item.url</a></div>
{{ endif }}
