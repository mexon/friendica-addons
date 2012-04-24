  <table>
    <tbody>
{{ for $feeds as $f }}
      <tr>
        <td>
          <img style="vertical-align:middle" src='$f.micro'>
          <span style="margin-left:1em">$f.name</span>
        </td>
        <td>
          <input type="submit" name="publicise$f.id" value="$submit">
        </td>
      </tr>
{{ endfor }}
    </tbody>
  </table>
