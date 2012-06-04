<div class="settings-block">
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
