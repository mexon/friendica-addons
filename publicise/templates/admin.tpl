{{*
 *	AUTOMATICALLY GENERATED TEMPLATE
 *	DO NOT EDIT THIS FILE, CHANGES WILL BE OVERWRITTEN
 *
 *}}
<form method="post">
  <table>
    <thead>
      <tr>
        <th>{{$feed_t}}</th>
        <th>{{$publicised_t}}</th>
        <th>{{$comments_t}}</th>
        <th>{{$expire_t}}</th>
      </tr>
    </thead>
    <tbody>
{{foreach $feeds as $f}}
      <tr>
        <td>
          <a href="{{$f.url}}">
            <img style="vertical-align:middle" src='{{$f.micro}}'>
            <span style="margin-left:1em">{{$f.name}}</span>
          </a>
        </td>
        <td>
{{include file="field_yesno.tpl" field=$f.enabled}}
        </td>
        <td>
{{include file="field_yesno.tpl" field=$f.comments}}
        </td>
        <td>
          <input name="publicise-expire-{{$f.id}}" value="{{$f.expire}}">
        </td>
      </tr>
{{/foreach}}
    </tbody>
  </table>
  <input type="submit" size="70" value="{{$submit_t}}">
</form>