<div class="settings-block">
  <h2>$title</h2>
  <form method="post">
    <input type="hidden" name="id" value="$id">
    <table>
      <tbody>
        <tr>
          <td>$enable_t:</td>
          <td><input class="checkbox" type="checkbox" name="enable" $enabled></td>
        </tr>
        <tr>
          <td>$pattern_t:</td>
          <td><input class="input" size="70" name="pattern" $pattern></td>
        </tr>
        <tr>
          <td>$replace_t:</td>
          <td><input class="input" size="70" name="replace" $replace></td>
        </tr>
        <tr>
          <td>$match_t:</td>
          <td><input class="input" size="70" name="match" $match></td>
        </tr>
        <tr>
          <td>$remove_t:</td>
          <td><input class="input" size="70" name="remove" $remove></td>
        </tr>
        <tr>
          <td>$images_t:</td>
          <td><input class="checkbox" type="checkbox" name="images" $images></td>
        </tr>
        <tr>
          <td colspan="2">$retrospective_t <input class="input" size="4" name="apply"> $posts_t</td>
        </tr>
        <tr>
          <td colspan="2"><input type="submit" size="70" value="$submit"></td>
        </tr>
      </tbody>
    </table>
  </form>
</div>
