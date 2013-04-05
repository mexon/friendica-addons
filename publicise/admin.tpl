<form method="post">
  <table>
    <thead>
      <tr>
        <th>Feed</th>
        <th>Publicised</th>
        <th>Allow Comments/Likes</th>
        <th>Expire Articles After (Days)</th>
      </tr>
    </thead>
    <tbody>
{{ for $feeds as $f }}
      <tr>
        <td>
          <a href="$f.url">
            <img style="vertical-align:middle" src='$f.micro'>
            <span style="margin-left:1em">$f.name</span>
          </a>
        </td>
        <td>
{{ inc field_yesno.tpl with $field=$f.enabled }}{{ endinc }}
        </td>
        <td>
{{ inc field_yesno.tpl with $field=$f.comments }}{{ endinc }}
        </td>
        <td>
          <input name="publicise-expire-$f.id" value="$f.expire">
        </td>
      </tr>
{{ endfor }}
    </tbody>
  </table>
  <input type="submit" size="70" value="$submit">
</form>
