  <p>
    Session: $window.sid
  </p>
  <p>
    Window: $window.wid
  </p>
  <p>
    Address: $window.addr
  </p>
  <p>
    Network: $window.network
  </p>
  <p>
    State: $window.state
  </p>
  <p>
    Last Update: $window.last-seen
  </p>
{{ for $sites as $s }}
<p>
      <a href="scraper/$nick/$window.wid/spawn/$s.name">Spawn $s.name window</a>
</p>
{{ endfor }}
{{ for $states as $s=>$d }}
<p>
      <a href="scraper/$nick/$window.wid/state/$s">State $d</a>
</p>
{{ endfor }}
<p>
  <a href="scraper/$nick/$window.wid/close">Close this window</a>
</p>
