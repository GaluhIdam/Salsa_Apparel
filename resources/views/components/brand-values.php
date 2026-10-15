<?php
$values = [
    ['Everyday Essentials', 'Core pieces you reach for first, every day of the week.'],
    ['Thoughtful Design', 'Clean cuts and honest materials, with nothing added for show.'],
    ['Versatile Styling', 'Pieces that pair easily and change with the occasion.'],
    ['Made for Everyday Wear', 'Comfortable, durable, and ready for a full day out.'],
];
?>
<section class="section section--tight" aria-label="Brand values">
    <div class="container values">
        <?php foreach ($values as [$name, $text]): ?>
            <div class="values__item">
                <h3><?= e($name) ?></h3>
                <p><?= e($text) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>
