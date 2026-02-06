<?php

function outrank_render_image($attachment_id, $alt = 'Post thumbnail') {
    if (!$attachment_id || !is_numeric($attachment_id)) {
        return '<div class="no-image">No image</div>';
    }

    return wp_get_attachment_image((int) $attachment_id, 'full', false, [
        'alt'   => esc_attr($alt),
        'class' => 'custom-thumbnail',
    ]);
}

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$articles = outrank_get_articles();

$per_page = 10;
$total = count($articles);
$total_pages = (int) ceil($total / $per_page);
$paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
if ($paged > $total_pages && $total_pages > 0) {
    $paged = $total_pages;
}
$offset = ($paged - 1) * $per_page;
$paged_articles = array_slice($articles, $offset, $per_page);
?>

<div class="outrank-container">
    <div class="outrank-card">
        <div class="outrank-header">
            <h2 class="outrank-title">Outrank Dashboard</h2>
            <form method="get" style="margin: 0;">
                <input type="hidden" name="page" value="outrank_manage">
                <button type="submit" class="outrank-fetch-btn">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right: 6px;">
                        <path d="M8 10C9.10457 10 10 9.10457 10 8C10 6.89543 9.10457 6 8 6C6.89543 6 6 6.89543 6 8C6 9.10457 6.89543 10 8 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M13.28 10.033C13.1913 10.2753 13.1738 10.5374 13.2293 10.7904C13.2848 11.0433 13.4114 11.2765 13.5935 11.4624L13.64 11.5093C13.7796 11.649 13.8904 11.815 13.9662 11.9985C14.042 12.182 14.0812 12.3791 14.0812 12.5785C14.0812 12.7779 14.042 12.9751 13.9662 13.1586C13.8904 13.3421 13.7796 13.5081 13.64 13.6478C13.5003 13.7874 13.3343 13.8982 13.1508 13.974C12.9673 14.0498 12.7701 14.089 12.5707 14.089C12.3713 14.089 12.1742 14.0498 11.9907 13.974C11.8072 13.8982 11.6412 13.7874 11.5015 13.6478L11.455 13.6013C11.2691 13.4192 11.0359 13.2926 10.783 13.2371C10.53 13.1815 10.2679 13.199 10.0256 13.2878C9.78782 13.3725 9.57843 13.5213 9.42031 13.7178C9.26219 13.9142 9.16133 14.1512 9.12896 14.4033V14.5333C9.12896 14.9455 8.96633 15.3408 8.67628 15.6308C8.38622 15.9209 7.99099 16.0835 7.57877 16.0835C7.16656 16.0835 6.77132 15.9209 6.48127 15.6308C6.19121 15.3408 6.02859 14.9455 6.02859 14.5333V14.4633C6.00996 14.2045 5.91791 13.9553 5.76209 13.7443C5.60627 13.5334 5.39248 13.3687 5.14373 13.2687C4.90158 13.1799 4.64045 13.1624 4.38755 13.2179C4.13465 13.2734 3.90151 13.4 3.71563 13.582L3.66912 13.6285C3.52949 13.7681 3.36349 13.8789 3.17999 13.9547C2.9965 14.0305 2.79935 14.0698 2.59995 14.0698C2.40055 14.0698 2.2034 14.0305 2.01991 13.9547C1.83641 13.8789 1.67041 13.7681 1.53078 13.6285C1.39116 13.4889 1.28035 13.3229 1.20455 13.1394C1.12875 12.9559 1.08951 12.7587 1.08951 12.5593C1.08951 12.3599 1.12875 12.1628 1.20455 11.9793C1.28035 11.7958 1.39116 11.6298 1.53078 11.4902L1.5773 11.4437C1.75937 11.2578 1.88597 11.0246 1.94148 10.7716C1.99699 10.5187 1.97936 10.2566 1.89058 10.0144C1.80588 9.77661 1.65703 9.56722 1.46055 9.4091C1.26406 9.25098 1.02708 9.15012 0.775082 9.11775H0.645079C0.232864 9.11775 -0.16237 8.95512 -0.452423 8.66507C-0.742476 8.37501 -0.905102 7.97978 -0.905102 7.56756C-0.905102 7.15535 -0.742476 6.76011 -0.452423 6.47006C-0.16237 6.18 0.232864 6.01738 0.645079 6.01738H0.715081C0.973733 5.99874 1.22289 5.90669 1.43386 5.75087C1.64483 5.59505 1.80959 5.38126 1.90992 5.13251C1.9987 4.89036 2.01618 4.62922 1.96067 4.37632C1.90515 4.12342 1.77855 3.89028 1.59648 3.70441L1.54996 3.65789C1.41034 3.51826 1.29954 3.35226 1.22373 3.16876C1.14793 2.98527 1.1087 2.78812 1.1087 2.58872C1.1087 2.38932 1.14793 2.19217 1.22373 2.00868C1.29954 1.82518 1.41034 1.65918 1.54996 1.51955C1.68959 1.37993 1.85559 1.26912 2.03909 1.19332C2.22258 1.11752 2.41973 1.07828 2.61913 1.07828C2.81853 1.07828 3.01568 1.11752 3.19918 1.19332C3.38267 1.26912 3.54868 1.37993 3.6883 1.51955L3.73482 1.56607C3.92069 1.74814 4.15383 1.87474 4.40673 1.93025C4.65963 1.98576 4.92077 1.96813 5.16292 1.87935H5.22626C5.46402 1.79465 5.67341 1.6458 5.83153 1.44931C5.98965 1.25283 6.09051 1.01585 6.12288 0.763853V0.633849C6.12288 0.221635 6.28551 -0.173599 6.57556 -0.463653C6.86562 -0.753706 7.26085 -0.916332 7.67307 -0.916332C8.08528 -0.916332 8.48052 -0.753706 8.77057 -0.463653C9.06063 -0.173599 9.22325 0.221635 9.22325 0.633849V0.703851C9.25562 0.955849 9.35648 1.19283 9.5146 1.38931C9.67272 1.5858 9.88211 1.73465 10.1199 1.81935C10.362 1.90813 10.6232 1.92576 10.8761 1.87025C11.129 1.81473 11.3621 1.68813 11.548 1.50606L11.5945 1.45955C11.7341 1.31992 11.9001 1.20912 12.0836 1.13332C12.2671 1.05752 12.4643 1.01828 12.6637 1.01828C12.8631 1.01828 13.0602 1.05752 13.2437 1.13332C13.4272 1.20912 13.5932 1.31992 13.7328 1.45955C13.8725 1.59917 13.9833 1.76517 14.0591 1.94867C14.1349 2.13216 14.1741 2.32931 14.1741 2.52871C14.1741 2.72811 14.1349 2.92526 14.0591 3.10876C13.9833 3.29225 13.8725 3.45826 13.7328 3.59788L13.6863 3.6444C13.5042 3.83027 13.3776 4.06341 13.3221 4.31631C13.2666 4.56921 13.2841 4.83035 13.3729 5.0725V5.136C13.4576 5.37376 13.6065 5.58315 13.8029 5.74127C13.9994 5.89939 14.2364 6.00025 14.4884 6.03262H14.6184C15.0306 6.03262 15.4259 6.19525 15.7159 6.4853C16.006 6.77536 16.1686 7.17059 16.1686 7.58281C16.1686 7.99502 16.006 8.39026 15.7159 8.68031C15.4259 8.97037 15.0306 9.13299 14.6184 9.13299H14.5484C14.2964 9.16536 14.0594 9.26622 13.863 9.42434C13.6665 9.58246 13.5177 9.79185 13.433 10.0296L13.28 10.033Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Manage
                </button>
            </form>
        </div>

        <div class="outrank-table-container">
            <?php if (!empty($articles)) : ?>
                <table class="outrank-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Slug</th>
                            <th>Meta Description</th>
                            <th>Image</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paged_articles as $post) : ?>
                            <tr>
                                <td>
                                    <div class="post-title">
                                        <?php echo esc_html($post->title); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="post-slug">
                                        <?php echo esc_html($post->slug); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="post-meta">
                                        <?php echo esc_html(wp_trim_words($post->meta_description, 12)); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="post-image">
                                    <?php echo wp_kses_post(outrank_render_image($post->image)); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr(strtolower($post->status)); ?>">
                                        <?php echo esc_html(ucfirst($post->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="post-date">
                                        <?php echo esc_html(date_i18n('M j, Y', strtotime($post->created_at))); ?>
                                        <br>
                                        <small style="opacity: 0.7;">
                                            <?php echo esc_html(date_i18n('g:i a', strtotime($post->created_at))); ?>
                                        </small>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages > 1) : ?>
                    <div class="outrank-pagination">
                        <span class="pagination-info">
                            Showing <?php echo esc_html($offset + 1); ?>–<?php echo esc_html(min($offset + $per_page, $total)); ?> of <?php echo esc_html($total); ?> articles
                        </span>
                        <div class="pagination-links">
                            <?php if ($paged > 1) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=outrank&paged=' . ($paged - 1))); ?>" class="pagination-btn pagination-prev">&lsaquo; Previous</a>
                            <?php else : ?>
                                <span class="pagination-btn pagination-prev pagination-disabled">&lsaquo; Previous</span>
                            <?php endif; ?>
                            <?php
                            $range = 2;
                            for ($i = 1; $i <= $total_pages; $i++) :
                                if ($i === 1 || $i === $total_pages || ($i >= $paged - $range && $i <= $paged + $range)) :
                                    if ($i === $paged) : ?>
                                        <span class="pagination-btn pagination-current"><?php echo esc_html($i); ?></span>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=outrank&paged=' . $i)); ?>" class="pagination-btn"><?php echo esc_html($i); ?></a>
                                    <?php endif;
                                elseif ($i === 2 || $i === $total_pages - 1) : ?>
                                    <span class="pagination-ellipsis">&hellip;</span>
                                <?php endif;
                            endfor;
                            ?>
                            <?php if ($paged < $total_pages) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=outrank&paged=' . ($paged + 1))); ?>" class="pagination-btn pagination-next">Next &rsaquo;</a>
                            <?php else : ?>
                                <span class="pagination-btn pagination-next pagination-disabled">Next &rsaquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="empty-state">
                    <div class="empty-state-text">No blog posts found</div>
                    <div class="empty-state-subtext">Publish first article in Outrank app to see it here</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>