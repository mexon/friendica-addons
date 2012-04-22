<div class="settings-block">
  <h3>$title</h3>
  <table>
    <thead><tr><th>Name</th><th>Enable</th><th>URL Pattern</th><th>URL Replace</th><th>Include</th><th>Exclude</th></tr></thead>
    <tbody>
      {{ for $feeds as $v }}
      <tr>
        <td>
          <img style="vertical-align:middle" src='$v.micro'>
          <span style="margin-left:1em">$v.name</span>
        </td>
        <td><input class='checkbox' type="checkbox" name='enable$v.id' {{ if $v.enable }}checked="true"{{ endif }} /></td>
        <td><input class='input' name='pattern$v.id' {{ if $v.pattern }}value='$v.pattern'{{ endif }} /></td>
        <td><input class='input' name='replace$v.id' {{ if $v.replace }}value='$v.replace'{{ endif }} /></td>
        <td><input class='input' name='match$v.id' {{ if $v.match }}value='$v.match'{{ endif }} /></td>
        <td><input class='input' name='remove$v.id' {{ if $v.remove }}value='$v.remove'{{ endif }} /></td>
      </tr>
      {{ endfor }}
    </tbody>
  </table>
  <div class="submit"><input type="submit" name="page_site" value="$submit"></div>
</div>
