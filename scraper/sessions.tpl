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
  <form>
    <table border="1">
      <thead>
        <tr>
          <th>Session</th>
          <th>Window</th>
          <th>Address</th>
          <th>Network</th>
          <th>State</th>
          <th>Last Update</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
{{ for $windows as $w }}
        <tr>
          <td>$w.sid</td>
          <td>$w.wid</td>
          <td>$w.addr</td>
          <td>$w.network</td>
          <td>$w.state</td>
          <td>$w.last</td>
          <td><a href="scraper/detail/$w.wid">details</a></td>
        </tr>
{{ endfor }}
      </tbody>
    </table>
    <input type="submit">
  </form>
</div>
