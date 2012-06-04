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
      <a href="scraper/spawn/$window.wid/$s.name">Spawn $s.name window</a>
</p>
{{ endfor }}
<p>
  <a href="scraper/close/$window.wid">Close this window</a>
</p>
