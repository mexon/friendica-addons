<div class="settings-block">
  <h3>Screen Scrapers</h3>
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
