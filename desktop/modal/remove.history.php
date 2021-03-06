<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
if (file_exists(__DIR__ . '/../../data/remove_history.json')) {
    $remove_history = json_decode(file_get_contents(__DIR__ . '/../../data/remove_history.json'), true);
}
if (!is_array($remove_history)) {
    $remove_history = array();
}
?>
<div id="div_alertRemoveHistory"></div>
<a class="btn btn-danger btn-sm pull-right" id="bt_emptyRemoveHistory"><i class="fas fa-trash-alt">&nbsp;&nbsp;</i>{{Vider}}</a>
<br/><br/>
<table class="table table-condensed table-bordered tablesorter">
    <thead>
        <tr>
            <th>{{Date}}</th>
            <th>{{Type}}</th>
            <th>{{ID}}</th>
            <th>{{Nom}}</th>
        </tr>
    </thead>
    <tbody>
        <?php
if (count($remove_history) > 0) {
    foreach ($remove_history as $remove) {
        echo '<tr>';
        echo '<td>';
        echo $remove['date'];
        echo '</td>';
        echo '<td>';
        echo $remove['type'];
        echo '</td>';
        echo '<td>';
        echo $remove['id'];
        echo '</td>';
        echo '<td>';
        echo $remove['name'];
        echo '</td>';
        echo '</tr>';
    }
}
?>
    </tbody>
</table>

<script>
    $('#bt_emptyRemoveHistory').on('click',function(){
        nextdom.emptyRemoveHistory({
            error: function (error) {
                notify('Core',error.message,'error');
            },
            success: function (data) {
                notify("Core",'{{Historique vidée avec succès}}',"success");
            }
        });
    });

    initTableSorter();
</script>
