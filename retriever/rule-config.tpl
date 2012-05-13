<div class="settings-block">
  <h2>$title</h2>
  <form method="post">
    <input type="hidden" name="id" value="$id">
    <table>
      <tbody>
        <tr>
          <td>Enabled:</td>
          <td><input class="checkbox" type="checkbox" name="enable" $enabled></td>
        </tr>
        <tr>
          <td>URL Pattern:</td>
          <td><input class="input" size="70" name="pattern" $pattern></td>
        </tr>
        <tr>
          <td>URL Replace:</td>
          <td><input class="input" size="70" name="replace" $replace></td>
        </tr>
        <tr>
          <td>Include:</td>
          <td><input class="input" size="70" name="match" $match></td>
        </tr>
        <tr>
          <td>Exclude:</td>
          <td><input class="input" size="70" name="remove" $remove></td>
        </tr>
        <tr>
          <td>Download Images:</td>
          <td><input class="checkbox" type="checkbox" name="images" $images></td>
        </tr>
        <tr>
          <td colspan="2">Retrospectively apply to the last <input class="input" size="4" name="apply"> posts</td>
        </tr>
        <tr>
          <td colspan="2"><input type="submit" size="70" value="$submit"></td>
        </tr>
      </tbody>
    </table>
  </form>
</div>
