<form method="post">
  <table>
    <thead>
      <tr>
        <th>$feed_t</th>
        <th>$publicised_t</th>
        <th>$comments_t</th>
        <th>$expire_t</th>
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
