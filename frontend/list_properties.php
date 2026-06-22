<?php
require 'config.php';
include 'header.php';

$properties = apiRequest('GET', '/properties');

/*
echo '<pre>';
var_dump($properties);
echo '</pre>';
exit;
*/
?>


<h1>My Properties</h1>

<table border="1" cellpadding="8">

    <tr>

        <th>ID</th>

        <th>Address</th>

        <th>Apartment</th>
        
        <th>City</th>

        <th>State</th>

        <th>Zip</th>

        <th>County</th>

        <th>Edit</th>

        <th>Delete</th>
    </tr>

    <?php
    foreach ($properties as $property) {
    ?>

        <tr>

            <td>
                <?= $property['id'] ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['street_address']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['apartment']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['city']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['state']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['zip']
                    ?? ''
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    $property['county'] 
                    ?? ''
                ) ?>
            </td>

            <td>

                <a href="edit_property.php?id=<?= $property['id'] ?>">

                    Edit

                </a>

            </td>

            <td>
                <form method="POST" action="delete_property.php" onsubmit="return confirm('Delete this property?');">
                <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                <button type="submit" style="color:red;">

                    Delete
                    
                </button>
    </form>
</td>

        </tr>

    <?php
    }
    ?>

</table>

<?php include 'footer.php'; ?>