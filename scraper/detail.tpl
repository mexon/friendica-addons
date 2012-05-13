<form action="scraper/detail/$scraper.guid" method="post">
  <p>
    Address: $scraper.address
  </p>
  <p>
    Type: $scraper.type
  </p>
  <p>
    Network: $scraper.network
  </p>
  <p>
    Status: $scraper.status
  </p>
  <p>
    Desired Status:
    <select name="want-status">
      <option {{ if $scraper.want-status==disconnected }} selected {{ endif }} value="disconnected">Disconnected</option>
      <option {{ if $scraper.want-status==connected }} selected {{ endif }} value="connected">Connected</option>
      <option {{ if $scraper.want-status==loggedin }} selected {{ endif }} value="loggedin">Logged In</option>
    </select>
  </p>
  <p>
    Activity: $scraper.activity
  </p>
  <p>
    Desired Activity: $scraper.want-activity
  </p>
  <p>
    Last Update: $scraper.update
  </p>
  <input type="submit">
</form>
<form action="scraper/login/$scraper.guid" method="post">
  <p>
    Username: <input name="username">
  </p>
  <p>
    Password: <input name="password">
  </p>
  <input type="submit">
</form>
