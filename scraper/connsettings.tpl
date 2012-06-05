<div class="settings-block">
  <h3>Screen Scrapers</h3>
  <p>
    To connect to LinkedIn, you will need to use your browser as a
    "screen scraper".  One window will be taken over by Friendica and
    watch your LinkedIn home page for updates, sending them to the
    server as soon as they arrive.  Occasionally it will also browse
    through your contacts list to synchronise with the server.
  </p>
  <p>
    If you want to do this, first make sure you
    have <a href="http://www.greasespot.net/">Greasemonkey</a>
    installed.  Then click <a href="$gmurl">this link</a> to download the
    Greasemonkey script for the screenscraper.
  </p>
  <table border="1">
    <thead>
      <tr>
        <th>Address</th>
        <th>Type</th>
        <th>Network</th>
        <th>Current Status</th>
        <th>Requested Status</th>
        <th>Current Activity</th>
        <th>Requested Activity</th>
        <th>Last Seen</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
{{ for $scrapers as $s }}
      <tr>
        <td>$s.address</td>
        <td>$s.type</td>
        <td>$s.network</td>
        <td>$s.status</td>
        <td>$s.want-status</td>
        <td>$s.activity</td>
        <td>$s.want-activity</td>
        <td>$s.update</td>
        <td><a href="scraper/detail/$s.guid">details</a></td>
      </tr>
{{ endfor }}
    </tbody>
  </table>
  <input type="submit">
</div>
